<?php
/**
 * CRM Campaigns: templates, campaigns, run (segment -> outbox).
 */

require_once __DIR__ . '/crm_repo.php';
require_once __DIR__ . '/logging.php';
if (file_exists(__DIR__ . '/flow_id.php')) {
    require_once __DIR__ . '/flow_id.php';
}

function crm_template_list(int $restaurantId): array
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id, name, channel, template_text, active, created_at FROM crm_templates WHERE restaurant_id = ? AND active = 1 ORDER BY name ASC");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('crm_template_list ' . $e->getMessage());
        return [];
    }
}

function crm_template_create(int $restaurantId, string $name, string $channel, string $templateText): int
{
    if (!crm_writes_allowed()) {
        return 0;
    }

    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO crm_templates (restaurant_id, name, channel, template_text, active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$restaurantId, $name, $channel ?: 'stub', $templateText]);
    return (int)$pdo->lastInsertId();
}

function crm_campaign_create(int $restaurantId, string $name, int $templateId, string $segmentType, int $delayDays): int
{
    if (!crm_writes_allowed()) {
        return 0;
    }

    $allowed = ['first_visit', 'no_visit_7_days', 'no_visit_14_days', 'vip_guests'];
    if (!in_array($segmentType, $allowed, true)) {
        throw new InvalidArgumentException('Invalid segment_type');
    }
    $pdo = db();
    $templateStmt = $pdo->prepare("SELECT id FROM crm_templates WHERE id = ? AND restaurant_id = ? AND active = 1 LIMIT 1");
    $templateStmt->execute([$templateId, $restaurantId]);
    if (!$templateStmt->fetchColumn()) {
        throw new InvalidArgumentException('Invalid template_id');
    }

    $stmt = $pdo->prepare("INSERT INTO crm_campaigns (restaurant_id, name, template_id, segment_type, delay_days, active) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->execute([$restaurantId, $name, $templateId, $segmentType, max(0, $delayDays)]);
    $id = (int)$pdo->lastInsertId();

    if (function_exists('app_event')) {
        app_event('crm_campaign_created', [
            'restaurant_id' => (int)$restaurantId,
            'campaign_id' => $id,
            'segment_type' => (string)$segmentType,
            'delay_days' => (int)max(0, $delayDays),
            'template_id' => (int)$templateId,
        ]);
    }

    return $id;
}

function crm_campaign_list(int $restaurantId): array
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT c.id, c.restaurant_id, c.name, c.template_id, c.segment_type, c.delay_days, c.active, c.created_at,
                   t.name AS template_name
            FROM crm_campaigns c
            LEFT JOIN crm_templates t ON t.id = c.template_id AND t.restaurant_id = c.restaurant_id
            WHERE c.restaurant_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->execute([$restaurantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('crm_campaign_list ' . $e->getMessage());
        return [];
    }
}

function crm_campaign_guest_ids(int $restaurantId, string $segmentType): array
{
    $pdo = db();
    switch ($segmentType) {
        case 'first_visit':
            $stmt = $pdo->prepare("
                SELECT id
                FROM crm_guests
                WHERE restaurant_id = ?
                  AND consent = 1
                  AND visits_count = 1
            ");
            $stmt->execute([$restaurantId]);
            break;
        case 'no_visit_7_days':
            $stmt = $pdo->prepare("
                SELECT id
                FROM crm_guests
                WHERE restaurant_id = ?
                  AND consent = 1
                  AND visits_count > 0
                  AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
            ");
            $stmt->execute([$restaurantId]);
            break;
        case 'no_visit_14_days':
            $stmt = $pdo->prepare("
                SELECT id
                FROM crm_guests
                WHERE restaurant_id = ?
                  AND consent = 1
                  AND visits_count > 0
                  AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL 14 DAY))
            ");
            $stmt->execute([$restaurantId]);
            break;
        case 'vip_guests':
            $stmt = $pdo->prepare("
                SELECT id
                FROM crm_guests
                WHERE restaurant_id = ?
                  AND consent = 1
                  AND visits_count >= 3
            ");
            $stmt->execute([$restaurantId]);
            break;
        default:
            return [];
    }

    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_COLUMN)) {
        $ids[] = (int)$row;
    }
    return $ids;
}

/**
 * Run campaign: select guests by segment, create crm_outbox rows. Only guests with consent=1.
 * @return int number of outbox rows created
 */
function crm_campaign_run(int $restaurantId, int $campaignId): int
{
    if (!crm_writes_allowed()) {
        return 0;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT id, restaurant_id, name, template_id, segment_type, delay_days
            FROM crm_campaigns
            WHERE id = ? AND restaurant_id = ? AND active = 1
            LIMIT 1
        ");
        $stmt->execute([$campaignId, $restaurantId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$campaign) {
            return 0;
        }

        $stmt = $pdo->prepare("
            SELECT id, channel, template_text
            FROM crm_templates
            WHERE id = ? AND restaurant_id = ? AND active = 1
            LIMIT 1
        ");
        $stmt->execute([(int)$campaign['template_id'], $restaurantId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) {
            return 0;
        }

        $segmentType = (string)$campaign['segment_type'];
        $delayDays = (int)$campaign['delay_days'];
        $channel = (string)($template['channel'] ?: 'stub');
        $text = (string)$template['template_text'];
        $templateName = 'campaign_' . $campaignId;
        $scheduledAt = date('Y-m-d H:i:s', strtotime('+' . $delayDays . ' days'));

        $reason = 'Кампания: ' . $segmentType . ' • задержка ' . $delayDays . ' дн.';
        $count = 0;
        foreach (crm_campaign_guest_ids($restaurantId, $segmentType) as $guestId) {
            if (crm_queue_outbox_message(
                $restaurantId,
                $guestId,
                $channel,
                $templateName,
                [
                    'text' => $text,
                    // Optional enrichment for CRM UI.
                    'reason' => $reason,
                    'suggested_items' => [],
                    'flow_id' => function_exists('app_flow_id_get_current') ? app_flow_id_get_current($restaurantId) : null,
                ],
                $scheduledAt,
                $campaignId,
                7
            )) {
                $count++;
            }
        }

        if (function_exists('app_event')) {
            app_event('crm_created', [
                'restaurant_id' => (int)$restaurantId,
                'campaign_id' => (int)$campaignId,
                'segment_type' => (string)$segmentType,
                'delay_days' => (int)$delayDays,
                'outbox_rows_created' => (int)$count,
            ]);
        }

        return $count;
    } catch (Throwable $e) {
        error_log('crm_campaign_run ' . $e->getMessage());
        return 0;
    }
}
