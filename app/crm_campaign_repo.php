<?php
/**
 * CRM Campaigns: templates, campaigns, run (segment -> outbox).
 */

require_once __DIR__ . '/crm_repo.php';
require_once __DIR__ . '/logging.php';
if (file_exists(__DIR__ . '/flow_id.php')) {
    require_once __DIR__ . '/flow_id.php';
}

function crm_loyalty_retention_segment_catalog(): array
{
    return [
        'loyalty_balance_inactive_14d' => [
            'label' => 'Есть бонусы, не был 14+ дней',
            'description' => 'Положительный бонусный баланс и нет оплаченного визита 14+ дней.',
            'goal' => 'Вернуть гостя, у которого уже есть ощутимый повод для повторного визита.',
            'who' => 'Гости с положительным бонусным балансом и паузой в оплаченных визитах от 14 дней.',
            'offer_framing' => 'Напомните, что бонусы уже лежат на счёте и их можно использовать в следующем QR-заказе.',
            'draft_title' => 'Напоминание о бонусах после паузы',
        ],
        'single_paid_order_no_return_14d' => [
            'label' => 'Один оплаченный визит, не вернулся',
            'description' => 'Был только один подтверждённый оплаченный визит и нет возврата 14+ дней.',
            'goal' => 'Довести нового гостя до второго визита, пока впечатление ещё свежее.',
            'who' => 'Гости с одним confirmed paid визитом, которые не вернулись в течение 14 дней.',
            'offer_framing' => 'Сделайте акцент на том, что бонусная карта уже активна и следующий визит закрепит привычку.',
            'draft_title' => 'Возврат после первого оплаченного визита',
        ],
        'loyalty_balance_no_spend_30d' => [
            'label' => 'Давно не тратил бонусы',
            'description' => 'Есть положительный баланс, но бонусы не списывались 30+ дней или ни разу не тратились.',
            'goal' => 'Сдвинуть гостя от накопления к реальному использованию бонусов.',
            'who' => 'Гости с бонусным балансом, у которых нет недавних операций списания.',
            'offer_framing' => 'Подсветите, что бонусы не просто копятся, а уже готовы уменьшить следующий чек.',
            'draft_title' => 'Пора использовать накопленные бонусы',
        ],
        'high_value_loyal_guest' => [
            'label' => 'Ценный гость',
            'description' => 'Высокий средний чек или суммарная выручка по подтверждённым заказам.',
            'goal' => 'Удержать сильного гостя персональным и уважительным касанием.',
            'who' => 'Гости с несколькими оплаченных визитами и высокой выручкой или средним чеком.',
            'offer_framing' => 'Сообщение должно звучать как благодарность и мягкое приглашение, а не как массовая рассылка.',
            'draft_title' => 'Персональное касание для ценного гостя',
        ],
        'loyalty_points_reminder_7d' => [
            'label' => 'Напомнить про бонусы',
            'description' => 'Есть положительный бонусный баланс и не было оплаченного визита 7+ дней.',
            'goal' => 'Мягко напомнить о бонусах до того, как гость начнёт о них забывать.',
            'who' => 'Гости с положительным балансом и короткой паузой в оплаченных визитах от 7 дней.',
            'offer_framing' => 'Это лёгкий reminder-сценарий без давления: бонусы уже ждут следующего заказа.',
            'draft_title' => 'Мягкое напоминание о бонусах',
        ],
    ];
}

function crm_loyalty_retention_template_library(): array
{
    return [
        'bonus_balance_reminder' => [
            'name' => 'Напоминание о бонусном балансе',
            'purpose' => 'Когда у гостя уже есть бонусы и важно мягко напомнить, что ими можно воспользоваться.',
            'template_text' => 'У вас уже есть {balance} бонусов. Их можно использовать в следующем QR-заказе и уменьшить сумму к оплате. Будем рады видеть вас снова.',
        ],
        'first_paid_visit_comeback' => [
            'name' => 'Возврат после первого оплаченного визита',
            'purpose' => 'Когда гость был у вас один раз и важно закрепить второй визит.',
            'template_text' => 'Спасибо за первый оплаченный визит. Для вас уже работает бонусная карта, а следующий заказ снова принесёт бонусы. Будем рады вашему возвращению.',
        ],
        'inactive_guest_with_points' => [
            'name' => 'Неактивный гость с бонусами',
            'purpose' => 'Когда у гостя остался баланс и он давно не приходил.',
            'template_text' => 'У вас уже накоплено {balance} бонусов. Они ждут следующего визита и могут уменьшить чек в новом QR-заказе. Заглядывайте снова.',
        ],
        'valuable_guest_thank_you' => [
            'name' => 'Спасибо ценному гостю',
            'purpose' => 'Для сильных гостей с высоким чеком или суммарной выручкой.',
            'template_text' => 'Спасибо, что выбираете нас снова. Для вас уже активна бонусная карта, и мы будем рады подготовить для вас следующий визит.',
        ],
        'soft_loyalty_return' => [
            'name' => 'Мягкое loyalty-возвращение',
            'purpose' => 'Когда нужен лёгкий повод вернуться без агрессивного оффера.',
            'template_text' => 'Напоминаем: у вас уже есть {balance} бонусов. Их можно списать при следующем заказе через QR, когда решите заглянуть снова.',
        ],
    ];
}

function crm_loyalty_retention_template_labels(): array
{
    return array_map(
        static fn(array $cfg): string => (string)($cfg['name'] ?? ''),
        crm_loyalty_retention_template_library()
    );
}

function crm_loyalty_retention_default_template_key_for_segment(string $segmentType): string
{
    return match ($segmentType) {
        'loyalty_balance_inactive_14d' => 'inactive_guest_with_points',
        'single_paid_order_no_return_14d' => 'first_paid_visit_comeback',
        'loyalty_balance_no_spend_30d' => 'bonus_balance_reminder',
        'high_value_loyal_guest' => 'valuable_guest_thank_you',
        'loyalty_points_reminder_7d' => 'soft_loyalty_return',
        default => 'soft_loyalty_return',
    };
}

function crm_loyalty_retention_template_catalog_for_segment(string $segmentType): array
{
    $library = crm_loyalty_retention_template_library();
    $recommended = crm_loyalty_retention_default_template_key_for_segment($segmentType);

    return [
        'recommended_key' => $recommended,
        'recommended_name' => (string)($library[$recommended]['name'] ?? ''),
        'options' => $library,
    ];
}

function crm_loyalty_retention_render_template_text(string $templateKey, array $candidate): string
{
    $library = crm_loyalty_retention_template_library();
    $templateKey = isset($library[$templateKey]) ? $templateKey : crm_loyalty_retention_default_template_key_for_segment((string)($candidate['segment_type'] ?? ''));
    $template = (string)($library[$templateKey]['template_text'] ?? '');
    if ($template === '') {
        return 'Будем рады видеть вас снова. Ваша бонусная карта уже активна.';
    }

    $balance = (int)($candidate['loyalty_balance'] ?? 0);
    $days = (int)($candidate['days_since_paid_visit'] ?? 0);
    $visits = (int)($candidate['visits_count'] ?? 0);
    $avgCheck = (float)($candidate['avg_check'] ?? 0);
    $totalSpent = (float)($candidate['total_spent'] ?? 0);

    return strtr($template, [
        '{balance}' => (string)$balance,
        '{days_since_paid_visit}' => (string)$days,
        '{visits_count}' => (string)$visits,
        '{avg_check}' => number_format($avgCheck, 0, '.', ' '),
        '{total_spent}' => number_format($totalSpent, 0, '.', ' '),
    ]);
}

function crm_campaign_segment_labels(): array
{
    return [
        'first_visit' => 'Первый визит',
        'no_visit_7_days' => 'Нет визита 7 дней',
        'no_visit_14_days' => 'Нет визита 14 дней',
        'vip_guests' => 'VIP (3+ визита)',
    ] + array_map(
        static fn(array $cfg): string => (string)$cfg['label'],
        crm_loyalty_retention_segment_catalog()
    );
}

function crm_campaign_allowed_segments(): array
{
    return array_keys(crm_campaign_segment_labels());
}

/**
 * @param list<int> $guestIds
 * @return array<int,array{balance:int,last_bonus_spend_at:?string,last_bonus_accrual_at:?string}>
 */
function crm_loyalty_enrichment_map(int $restaurantId, array $guestIds): array
{
    $map = [];
    $restaurantId = (int)$restaurantId;
    $guestIds = array_values(array_unique(array_filter(array_map('intval', $guestIds), static fn(int $v): bool => $v > 0)));
    if ($restaurantId <= 0 || $guestIds === [] || !function_exists('db')) {
        return $map;
    }

    foreach ($guestIds as $gid) {
        $map[$gid] = [
            'balance' => 0,
            'last_bonus_spend_at' => null,
            'last_bonus_accrual_at' => null,
        ];
    }

    $pdo = db();
    $ph = implode(',', array_fill(0, count($guestIds), '?'));

    try {
        if (function_exists('db_table_exists') && db_table_exists('guest_loyalty_accounts')) {
            $stmt = $pdo->prepare("
                SELECT guest_id, balance
                FROM guest_loyalty_accounts
                WHERE restaurant_id = ?
                  AND guest_id IN ({$ph})
            ");
            $stmt->execute(array_merge([$restaurantId], $guestIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $gid = (int)($row['guest_id'] ?? 0);
                if ($gid > 0) {
                    $map[$gid]['balance'] = (int)($row['balance'] ?? 0);
                }
            }
        }

        if (function_exists('db_table_exists') && db_table_exists('guest_loyalty_tx')) {
            $stmt = $pdo->prepare("
                SELECT
                    guest_id,
                    MAX(CASE WHEN type = 'spend' THEN created_at ELSE NULL END) AS last_bonus_spend_at,
                    MAX(CASE WHEN type = 'accrual' THEN created_at ELSE NULL END) AS last_bonus_accrual_at
                FROM guest_loyalty_tx
                WHERE restaurant_id = ?
                  AND guest_id IN ({$ph})
                GROUP BY guest_id
            ");
            $stmt->execute(array_merge([$restaurantId], $guestIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $gid = (int)($row['guest_id'] ?? 0);
                if ($gid > 0 && isset($map[$gid])) {
                    $map[$gid]['last_bonus_spend_at'] = $row['last_bonus_spend_at'] ?: null;
                    $map[$gid]['last_bonus_accrual_at'] = $row['last_bonus_accrual_at'] ?: null;
                }
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('crm_loyalty_enrichment_map rid=' . $restaurantId . ' ' . $e->getMessage());
        }
    }

    return $map;
}

function crm_loyalty_retention_candidate_matches(string $segmentType, array $candidate): bool
{
    $visits = (int)($candidate['visits_count'] ?? 0);
    $balance = (int)($candidate['loyalty_balance'] ?? 0);
    $daysSinceVisit = (int)($candidate['days_since_paid_visit'] ?? 0);
    $avgCheck = (float)($candidate['avg_check'] ?? 0);
    $totalSpent = (float)($candidate['total_spent'] ?? 0);
    $lastSpendAt = trim((string)($candidate['last_bonus_spend_at'] ?? ''));
    $lastSpendTs = $lastSpendAt !== '' ? strtotime($lastSpendAt) : false;

    return match ($segmentType) {
        'loyalty_balance_inactive_14d' => $balance > 0 && $daysSinceVisit >= 14,
        'single_paid_order_no_return_14d' => $visits === 1 && $daysSinceVisit >= 14,
        'loyalty_balance_no_spend_30d' => $balance > 0 && ($lastSpendTs === false || $lastSpendTs < strtotime('-30 days')),
        'high_value_loyal_guest' => $visits >= 2 && ($avgCheck >= 2000 || $totalSpent >= 15000),
        'loyalty_points_reminder_7d' => $balance > 0 && $daysSinceVisit >= 7,
        default => false,
    };
}

function crm_loyalty_retention_reason_text(string $segmentType, array $candidate): string
{
    $balance = (int)($candidate['loyalty_balance'] ?? 0);
    $days = (int)($candidate['days_since_paid_visit'] ?? 0);
    $visits = (int)($candidate['visits_count'] ?? 0);
    $avgCheck = (float)($candidate['avg_check'] ?? 0);
    $totalSpent = (float)($candidate['total_spent'] ?? 0);

    return match ($segmentType) {
        'loyalty_balance_inactive_14d' => 'Есть ' . $balance . ' бонусов, а подтверждённого оплаченного визита не было уже ' . $days . ' дн.',
        'single_paid_order_no_return_14d' => 'После первого оплаченного визита прошло ' . $days . ' дн., повторного визита пока нет.',
        'loyalty_balance_no_spend_30d' => 'На счёте ' . $balance . ' бонусов, но guest давно не использовал их в заказах.',
        'high_value_loyal_guest' => 'Ценный гость: ' . $visits . ' визитов, ' . number_format($totalSpent, 0, '.', ' ') . ' ₽ оплаченной выручки, средний чек ' . number_format($avgCheck, 0, '.', ' ') . ' ₽.',
        'loyalty_points_reminder_7d' => 'Есть ' . $balance . ' бонусов и пауза в оплаченных визитах уже ' . $days . ' дн.',
        default => '',
    };
}

function crm_loyalty_retention_message_text(string $segmentType, array $candidate): string
{
    $candidate['segment_type'] = $segmentType;
    $templateKey = (string)($candidate['selected_template_key'] ?? crm_loyalty_retention_default_template_key_for_segment($segmentType));
    return crm_loyalty_retention_render_template_text($templateKey, $candidate);
}

/**
 * @return list<array<string,mixed>>
 */
function crm_loyalty_retention_candidates(int $restaurantId, string $segmentType, int $limit = 20): array
{
    $restaurantId = (int)$restaurantId;
    $limit = max(1, min(200, $limit));
    $catalog = crm_loyalty_retention_segment_catalog();
    if ($restaurantId <= 0 || !isset($catalog[$segmentType])) {
        return [];
    }

    $rows = crm_confirmed_guest_metrics_rows($restaurantId);
    if ($rows === []) {
        return [];
    }

    $loyaltyGuestIds = [];
    foreach ($rows as $row) {
        $lgid = (int)($row['loyalty_guest_id'] ?? 0);
        if ($lgid > 0) {
            $loyaltyGuestIds[] = $lgid;
        }
    }
    $enrichment = crm_loyalty_enrichment_map($restaurantId, $loyaltyGuestIds);

    $out = [];
    foreach ($rows as $row) {
        if ((int)($row['consent'] ?? 0) !== 1) {
            continue;
        }
        $crmGuestId = (int)($row['id'] ?? 0);
        $loyaltyGuestId = (int)($row['loyalty_guest_id'] ?? 0);
        if ($crmGuestId <= 0 || $loyaltyGuestId <= 0) {
            continue;
        }
        $lastSeen = trim((string)($row['last_seen_at'] ?? ''));
        $lastSeenTs = $lastSeen !== '' ? strtotime($lastSeen) : false;
        $daysSinceVisit = $lastSeenTs === false ? 9999 : max(0, (int)floor((time() - $lastSeenTs) / 86400));
        $orderCount = (int)($row['order_count'] ?? 0);
        $totalSpent = (float)($row['total_spent'] ?? 0);
        $avgCheck = $orderCount > 0 ? round($totalSpent / $orderCount, 2) : 0.0;
        $loyalty = $enrichment[$loyaltyGuestId] ?? ['balance' => 0, 'last_bonus_spend_at' => null, 'last_bonus_accrual_at' => null];

        $candidate = [
            'id' => $crmGuestId,
            'crm_guest_id' => $crmGuestId,
            'loyalty_guest_id' => $loyaltyGuestId,
            'phone' => (string)($row['phone'] ?? ''),
            'visits_count' => (int)($row['visits_count'] ?? 0),
            'order_count' => $orderCount,
            'total_spent' => $totalSpent,
            'avg_check' => $avgCheck,
            'last_seen_at' => $lastSeen !== '' ? $lastSeen : null,
            'days_since_paid_visit' => $daysSinceVisit,
            'loyalty_balance' => (int)($loyalty['balance'] ?? 0),
            'last_bonus_spend_at' => $loyalty['last_bonus_spend_at'] ?? null,
            'last_bonus_accrual_at' => $loyalty['last_bonus_accrual_at'] ?? null,
            'segment_type' => $segmentType,
            'segment_label' => (string)$catalog[$segmentType]['label'],
            'scenario_description' => (string)($catalog[$segmentType]['description'] ?? ''),
            'scenario_goal' => (string)($catalog[$segmentType]['goal'] ?? ''),
            'scenario_who' => (string)($catalog[$segmentType]['who'] ?? ''),
            'scenario_offer_framing' => (string)($catalog[$segmentType]['offer_framing'] ?? ''),
            'draft_title' => (string)($catalog[$segmentType]['draft_title'] ?? $catalog[$segmentType]['label']),
            'recommended_template_key' => crm_loyalty_retention_default_template_key_for_segment($segmentType),
        ];

        if (!crm_loyalty_retention_candidate_matches($segmentType, $candidate)) {
            continue;
        }

        $candidate['reason_text'] = crm_loyalty_retention_reason_text($segmentType, $candidate);
        $templateMeta = crm_loyalty_retention_template_catalog_for_segment($segmentType);
        $candidate['recommended_template_key'] = (string)($templateMeta['recommended_key'] ?? crm_loyalty_retention_default_template_key_for_segment($segmentType));
        $candidate['recommended_template_name'] = (string)($templateMeta['recommended_name'] ?? '');
        $candidate['template_options'] = $templateMeta['options'] ?? [];
        $candidate['message_text'] = crm_loyalty_retention_message_text($segmentType, $candidate);
        $out[] = $candidate;
    }

    usort($out, static function (array $a, array $b) use ($segmentType): int {
        return match ($segmentType) {
            'high_value_loyal_guest' =>
                ((float)($b['total_spent'] ?? 0) <=> (float)($a['total_spent'] ?? 0))
                ?: ((float)($b['avg_check'] ?? 0) <=> (float)($a['avg_check'] ?? 0)),
            'single_paid_order_no_return_14d' =>
                ((int)($b['days_since_paid_visit'] ?? 0) <=> (int)($a['days_since_paid_visit'] ?? 0)),
            default =>
                ((int)($b['loyalty_balance'] ?? 0) <=> (int)($a['loyalty_balance'] ?? 0))
                ?: ((int)($b['days_since_paid_visit'] ?? 0) <=> (int)($a['days_since_paid_visit'] ?? 0)),
        };
    });

    return array_slice($out, 0, $limit);
}

function crm_loyalty_retention_candidate_by_guest(int $restaurantId, string $segmentType, int $crmGuestId): ?array
{
    foreach (crm_loyalty_retention_candidates($restaurantId, $segmentType, 500) as $candidate) {
        if ((int)($candidate['crm_guest_id'] ?? 0) === $crmGuestId) {
            return $candidate;
        }
    }
    return null;
}

function crm_loyalty_retention_segment_priority_weight(string $segmentType): int
{
    return match ($segmentType) {
        'high_value_loyal_guest' => 120,
        'single_paid_order_no_return_14d' => 110,
        'loyalty_balance_inactive_14d' => 100,
        'loyalty_balance_no_spend_30d' => 90,
        'loyalty_points_reminder_7d' => 70,
        default => 50,
    };
}

function crm_loyalty_retention_priority_score(array $candidate): int
{
    $segmentType = (string)($candidate['segment_type'] ?? '');
    $balance = (int)($candidate['loyalty_balance'] ?? 0);
    $days = (int)($candidate['days_since_paid_visit'] ?? 0);
    $visits = (int)($candidate['visits_count'] ?? 0);
    $totalSpent = (float)($candidate['total_spent'] ?? 0);
    $avgCheck = (float)($candidate['avg_check'] ?? 0);

    $score = crm_loyalty_retention_segment_priority_weight($segmentType);
    $score += min(40, $balance);
    $score += min(30, $days);
    $score += min(20, $visits * 3);
    $score += min(25, (int)round($avgCheck / 300));
    $score += min(35, (int)round($totalSpent / 1500));

    return $score;
}

/**
 * Main CRM screen queue: who should the restaurant return first.
 *
 * @return list<array<string,mixed>>
 */
function crm_loyalty_retention_priority_queue(int $restaurantId, int $limit = 8): array
{
    $restaurantId = (int)$restaurantId;
    $limit = max(1, min(20, $limit));
    $catalog = crm_loyalty_retention_segment_catalog();
    if ($restaurantId <= 0 || $catalog === []) {
        return [];
    }

    $queue = [];
    foreach (array_keys($catalog) as $segmentType) {
        foreach (crm_loyalty_retention_candidates($restaurantId, $segmentType, 12) as $candidate) {
            $crmGuestId = (int)($candidate['crm_guest_id'] ?? 0);
            if ($crmGuestId <= 0) {
                continue;
            }
            $candidate['priority_score'] = crm_loyalty_retention_priority_score($candidate);
            $existing = $queue[$crmGuestId] ?? null;
            if ($existing === null || (int)($candidate['priority_score'] ?? 0) > (int)($existing['priority_score'] ?? 0)) {
                $queue[$crmGuestId] = $candidate;
            }
        }
    }

    $queue = array_values($queue);
    usort($queue, static function (array $a, array $b): int {
        $scoreCmp = ((int)($b['priority_score'] ?? 0) <=> (int)($a['priority_score'] ?? 0));
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        $daysCmp = ((int)($b['days_since_paid_visit'] ?? 0) <=> (int)($a['days_since_paid_visit'] ?? 0));
        if ($daysCmp !== 0) {
            return $daysCmp;
        }
        return ((int)($b['loyalty_balance'] ?? 0) <=> (int)($a['loyalty_balance'] ?? 0));
    });

    $queue = array_slice($queue, 0, $limit);
    $feedbackMap = function_exists('get_loyalty_retention_guest_feedback_map')
        ? get_loyalty_retention_guest_feedback_map($restaurantId, $queue, 90)
        : [];
    foreach ($queue as &$candidate) {
        $crmGuestId = (int)($candidate['crm_guest_id'] ?? 0);
        $segmentType = (string)($candidate['segment_type'] ?? '');
        $pairKey = $crmGuestId . ':' . $segmentType;
        $recommendedTemplateKey = (string)($candidate['recommended_template_key'] ?? crm_loyalty_retention_default_template_key_for_segment($segmentType));
        $block = function_exists('crm_outbox_recent_blocking_manual_return_state')
            ? crm_outbox_recent_blocking_manual_return_state($restaurantId, $crmGuestId, 7)
            : null;
        $feedback = $feedbackMap[$pairKey] ?? [];

        $candidate['recommended_template_key'] = $recommendedTemplateKey;
        $candidate['recommended_template_name'] = (string)($candidate['recommended_template_name'] ?? (crm_loyalty_retention_template_library()[$recommendedTemplateKey]['name'] ?? ''));
        $candidate['existing_outbox_id'] = (int)($block['id'] ?? 0);
        $candidate['existing_outbox_status'] = (string)($block['status'] ?? '');
        $candidate['existing_outbox_segment_type'] = (string)($block['segment_type'] ?? '');
        $candidate['existing_outbox_segment_label'] = (string)($block['segment_label'] ?? '');
        $candidate['existing_outbox_template_name'] = (string)($block['template_name'] ?? '');
        $candidate['existing_outbox_created_at'] = (string)($block['created_at'] ?? '');
        $candidate['has_existing_draft_for_same_segment'] = $block !== null
            && (string)($block['segment_type'] ?? '') === $segmentType;
        $candidate['has_any_existing_blocking_draft'] = $block !== null;
        $candidate['can_one_click_create'] = $block === null;
        $candidate['feedback_has_same_segment_draft'] = !empty($feedback['has_same_segment_draft']);
        $candidate['feedback_outbox_id'] = (int)($feedback['outbox_id'] ?? 0);
        $candidate['feedback_outbox_status'] = (string)($feedback['outbox_status'] ?? '');
        $candidate['feedback_outbox_created_at'] = (string)($feedback['outbox_created_at'] ?? '');
        $candidate['feedback_template_name'] = (string)($feedback['template_name'] ?? '');
        $candidate['feedback_returned'] = !empty($feedback['returned']);
        $candidate['feedback_return_order_id'] = (int)($feedback['return_order_id'] ?? 0);
        $candidate['feedback_return_order_created_at'] = (string)($feedback['return_order_created_at'] ?? '');
        $candidate['feedback_return_order_total'] = (float)($feedback['return_order_total'] ?? 0);
        $candidate['feedback_days_to_return'] = $feedback['days_to_return'] ?? null;
    }
    unset($candidate);

    return $queue;
}

/**
 * @return array{ok:bool,reason?:string,outbox_id?:int}
 */
function crm_create_loyalty_retention_outbox_draft(int $restaurantId, string $segmentType, int $crmGuestId, ?string $templateKey = null): array
{
    if (!crm_writes_allowed() || $restaurantId <= 0 || $crmGuestId <= 0) {
        return ['ok' => false, 'reason' => 'writes_disabled'];
    }
    $candidate = crm_loyalty_retention_candidate_by_guest($restaurantId, $segmentType, $crmGuestId);
    if ($candidate === null) {
        return ['ok' => false, 'reason' => 'candidate_not_found'];
    }
    $templateLibrary = crm_loyalty_retention_template_library();
    $selectedTemplateKey = trim((string)$templateKey);
    if ($selectedTemplateKey === '' || !isset($templateLibrary[$selectedTemplateKey])) {
        $selectedTemplateKey = (string)($candidate['recommended_template_key'] ?? crm_loyalty_retention_default_template_key_for_segment($segmentType));
    }
    $candidate['selected_template_key'] = $selectedTemplateKey;
    $candidate['selected_template_name'] = (string)($templateLibrary[$selectedTemplateKey]['name'] ?? $selectedTemplateKey);
    $candidate['message_text'] = crm_loyalty_retention_message_text($segmentType, $candidate);

    $block = function_exists('crm_outbox_recent_blocking_manual_return')
        ? crm_outbox_recent_blocking_manual_return($restaurantId, $crmGuestId, 7)
        : null;
    if ($block !== null) {
        return ['ok' => false, 'reason' => 'duplicate', 'outbox_id' => (int)($block['id'] ?? 0)];
    }

    $payload = [
        'guest_id' => (int)$crmGuestId,
        'crm_guest_id' => (int)$crmGuestId,
        'loyalty_guest_id' => (int)($candidate['loyalty_guest_id'] ?? 0),
        'phone' => (string)($candidate['phone'] ?? ''),
        'text' => (string)($candidate['message_text'] ?? ''),
        'message_text' => (string)($candidate['message_text'] ?? ''),
        'draft_title' => (string)($candidate['draft_title'] ?? ''),
        'template_key' => $selectedTemplateKey,
        'template_name' => (string)($candidate['selected_template_name'] ?? ''),
        'reason' => 'loyalty_retention:' . $segmentType,
        'segment_type' => $segmentType,
        'segment_label' => (string)($candidate['segment_label'] ?? $segmentType),
        'reason_text' => (string)($candidate['reason_text'] ?? ''),
        'scenario_description' => (string)($candidate['scenario_description'] ?? ''),
        'scenario_goal' => (string)($candidate['scenario_goal'] ?? ''),
        'scenario_who' => (string)($candidate['scenario_who'] ?? ''),
        'scenario_offer_framing' => (string)($candidate['scenario_offer_framing'] ?? ''),
        'visits_count' => (int)($candidate['visits_count'] ?? 0),
        'days_since_paid_visit' => (int)($candidate['days_since_paid_visit'] ?? 0),
        'loyalty_balance' => (int)($candidate['loyalty_balance'] ?? 0),
        'avg_order_value' => (float)($candidate['avg_check'] ?? 0),
        'total_spent' => (float)($candidate['total_spent'] ?? 0),
        'manual_prepare' => true,
        'source' => 'loyalty_retention_rule',
    ];

    if (function_exists('crm_outbox_insert_manual_return_row') && crm_outbox_insert_manual_return_row($restaurantId, $crmGuestId, $payload, 'draft')) {
        return ['ok' => true];
    }

    return ['ok' => false, 'reason' => 'insert_failed'];
}

/**
 * @return array{created:int,skipped_duplicate:int,skipped_other:int}
 */
function crm_bulk_create_loyalty_retention_outbox_drafts(int $restaurantId, string $segmentType, int $maxCreate = 25, ?string $templateKey = null): array
{
    $result = ['created' => 0, 'skipped_duplicate' => 0, 'skipped_other' => 0];
    $maxCreate = max(1, min(50, $maxCreate));

    $n = 0;
    foreach (crm_loyalty_retention_candidates($restaurantId, $segmentType, 200) as $candidate) {
        if ($n >= $maxCreate) {
            break;
        }
        $crmGuestId = (int)($candidate['crm_guest_id'] ?? 0);
        if ($crmGuestId <= 0) {
            $result['skipped_other']++;
            continue;
        }
        $res = crm_create_loyalty_retention_outbox_draft($restaurantId, $segmentType, $crmGuestId, $templateKey);
        if (!empty($res['ok'])) {
            $result['created']++;
            $n++;
        } elseif (($res['reason'] ?? '') === 'duplicate') {
            $result['skipped_duplicate']++;
        } else {
            $result['skipped_other']++;
        }
    }

    return $result;
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

    $allowed = crm_campaign_allowed_segments();
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
    if (isset(crm_loyalty_retention_segment_catalog()[$segmentType])) {
        return array_values(array_map(
            static fn(array $row): int => (int)($row['crm_guest_id'] ?? 0),
            crm_loyalty_retention_candidates($restaurantId, $segmentType, 500)
        ));
    }

    $ids = [];
    $cutoffDays = null;
    if ($segmentType === 'no_visit_7_days') {
        $cutoffDays = 7;
    } elseif ($segmentType === 'no_visit_14_days') {
        $cutoffDays = 14;
    }

    foreach (crm_confirmed_guest_metrics_rows($restaurantId) as $row) {
        if ((int)($row['consent'] ?? 0) !== 1) {
            continue;
        }
        $guestId = (int)($row['id'] ?? 0);
        $visits = (int)($row['visits_count'] ?? 0);
        $lastSeen = trim((string)($row['last_seen_at'] ?? ''));
        if ($guestId <= 0) {
            continue;
        }

        $match = false;
        switch ($segmentType) {
            case 'first_visit':
                $match = ($visits === 1);
                break;
            case 'no_visit_7_days':
            case 'no_visit_14_days':
                if ($visits > 0) {
                    $lastTs = $lastSeen !== '' ? strtotime($lastSeen) : false;
                    $match = ($lastTs === false) || ($lastTs < strtotime('-' . (int)$cutoffDays . ' days'));
                }
                break;
            case 'vip_guests':
                $match = ($visits >= 3);
                break;
            default:
                return [];
        }

        if ($match) {
            $ids[] = $guestId;
        }
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
