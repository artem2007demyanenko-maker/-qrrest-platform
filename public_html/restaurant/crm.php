<?php

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/crm_repo.php';
if (file_exists(__DIR__ . '/../../app/feedback_crm_bridge.php')) {
    require_once __DIR__ . '/../../app/feedback_crm_bridge.php';
}
if (file_exists(__DIR__ . '/../../app/retention_analytics.php')) {
    require_once __DIR__ . '/../../app/retention_analytics.php';
}
if (file_exists(__DIR__ . '/../../app/guest_retention.php')) {
    require_once __DIR__ . '/../../app/guest_retention.php';
}

$rid = bin2hex(random_bytes(4));
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('RESTAURANT_CRM rid=' . $rid . ' ' . $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<h1>Ошибка</h1><p>Попробуйте позже.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p>';
    exit;
});

require_login();
require_current_restaurant();
require_current_restaurant_role(['owner', 'admin']);

if (file_exists(__DIR__ . '/../../app/trial_guard.php')) {
    require_once __DIR__ . '/../../app/trial_guard.php';
}
$trialRequiresUpgrade = is_demo_mode() ? false : (function_exists('trial_guard_requires_upgrade') && trial_guard_requires_upgrade());
$trialInfo = function_exists('trial_guard_trial_info') ? trial_guard_trial_info() : ['is_trial' => false, 'days_left' => 0, 'is_expired' => false, 'has_active_paid_plan' => false];

$restId = (int)$currentRestaurant['id'];

// Soft CRM gating: allow read-only access; block mutations when feature disabled (demo unchanged).
$crmEnabled = true;
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
    $crmEnabled = function_exists('check_feature') && check_feature($restId, 'crm_enabled');
}

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$success = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_demo_mode()) {
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$csrfOk) {
        $errors[] = 'Неверный токен. Обновите страницу.';
    } else {
        $action = trim($_POST['action'] ?? '');
        $allowWithoutFullCrm = ($action === 'save_manual_return')
            || $action === 'create_inactive_return_draft'
            || $action === 'bulk_inactive_return_drafts'
            || (function_exists('crm_outbox_manual_prepare_action_allowed')
                && crm_outbox_manual_prepare_action_allowed($restId, $action, $_POST));
        if (!$crmEnabled && !$allowWithoutFullCrm) {
            $errors[] = 'CRM-возврат гостей доступен на тарифе GROWTH. Подключите CRM в разделе тарифов.';
        }
    }
    if ($errors === []) {
        if ($action === 'cancel') {
            $outboxId = (int)($_POST['id'] ?? 0);
            if ($outboxId <= 0) {
                $errors[] = 'Некорректная запись.';
            } elseif (crm_cancel_outbox($restId, $outboxId)) {
                $success = 'Сообщение отменено.';
            } else {
                $errors[] = 'Не удалось отменить.';
            }
        } elseif ($action === 'send_manual') {
            $outboxId = (int)($_POST['id'] ?? 0);
            if ($outboxId > 0 && function_exists('crm_mark_outbox_sent_manual') && crm_mark_outbox_sent_manual($restId, $outboxId)) {
                $success = 'Сообщение отмечено как отправленное (manual).';
            } else {
                $errors[] = 'Не удалось отметить сообщение.';
            }
        } elseif ($action === 'schedule_comeback') {
            $guestId = (int)($_POST['guest_id'] ?? 0);
            if ($guestId > 0 && function_exists('crm_schedule_comeback')) {
                try {
                    if (crm_schedule_comeback($restId, $guestId, 7)) {
                        $success = 'Напоминание запланировано.';
                    } else {
                        $errors[] = 'Напоминание уже есть в очереди или гость недоступен для связи.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Не удалось запланировать.';
                }
            }
        } elseif ($action === 'create_retention_drafts' && function_exists('get_retention_opportunities')) {
            if (file_exists(__DIR__ . '/../../app/growth_engine_arch.php') && function_exists('db') && function_exists('db_table_exists')) {
                require_once __DIR__ . '/../../app/growth_engine_arch.php';
                if (db_table_exists('growth_engine_suggestions')) {
                    try {
                        $pdo = db();
                        $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                        $opps = get_retention_opportunities($restId);
                        $created = 0;
                        foreach (array_slice($opps, 0, 20) as $opp) {
                            $payload = json_encode([
                                'guest_contact' => (string)($opp['guest_contact'] ?? ''),
                                'guest_name'    => $opp['guest_name'] ?? null,
                                'message'       => (string)($opp['message'] ?? 'We miss you! Come back this week and enjoy a special offer.'),
                            ], JSON_UNESCAPED_UNICODE);
                            if (!is_string($payload)) {
                                continue;
                            }

                            $title = 'Retention draft for ' . (string)($opp['guest_contact'] ?? '');
                            $description = 'Draft comeback message for inactive guest. Review and send via CRM.';
                            if (function_exists('growth_engine_suggestion_duplicate_exists')
                                && growth_engine_suggestion_duplicate_exists($pdo, $restId, 'crm_retention_draft', $payload, $title)
                            ) {
                                continue;
                            }

                            if ($hasStatus) {
                                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'crm_retention_draft', ?, ?, ?, 'medium', 'guest_retention', 'pending')");
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'crm_retention_draft', ?, ?, ?)");
                            }
                            $stmt->execute([$restId, $title, $description, $payload]);
                            $created++;
                        }
                        if ($created > 0) {
                            $success = 'Черновики кампаний по возврату гостей созданы (' . $created . ').';
                        } else {
                            $errors[] = 'Нет новых гостей для создания черновиков кампаний.';
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Не удалось создать черновики кампаний.';
                    }
                }
            }
        } elseif ($action === 'create_comeback_draft') {
            $contact = trim((string)($_POST['guest_contact'] ?? ''));
            $offer = trim((string)($_POST['suggested_offer'] ?? ''));
            $message = trim((string)($_POST['suggested_message'] ?? ''));
            $score = (int)($_POST['score'] ?? 0);
            $name = trim((string)($_POST['guest_name'] ?? ''));
            if ($contact !== '' && file_exists(__DIR__ . '/../../app/growth_engine_arch.php') && function_exists('db_table_exists') && db_table_exists('growth_engine_suggestions')) {
                require_once __DIR__ . '/../../app/growth_engine_arch.php';
                $pdo = db();
                $payload = json_encode(['guest_contact' => $contact, 'guest_name' => $name ?: null, 'suggested_offer' => $offer, 'message' => $message, 'score' => $score], JSON_UNESCAPED_UNICODE);
                $title = 'Guest return: ' . $contact;
                if (function_exists('growth_engine_suggestion_duplicate_exists') && growth_engine_suggestion_duplicate_exists($pdo, $restId, 'guest_return_offer', $payload, $title)) {
                    $errors[] = 'Draft for this guest was already created recently.';
                } else {
                    $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                    if ($hasStatus) {
                        $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'guest_return_offer', ?, ?, ?, 'medium', 'guest_return_engine', 'pending')");
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'guest_return_offer', ?, ?, ?)");
                    }
                    $stmt->execute([$restId, $title, 'Comeback offer draft. Review and send via CRM.', $payload]);
                    $success = 'Comeback draft created.';
                }
            }
        } elseif ($action === 'create_guest_return_drafts') {
            if (file_exists(__DIR__ . '/../../app/guest_return_engine.php') && file_exists(__DIR__ . '/../../app/growth_engine_arch.php') && function_exists('db_table_exists') && db_table_exists('growth_engine_suggestions')) {
                require_once __DIR__ . '/../../app/guest_return_engine.php';
                require_once __DIR__ . '/../../app/growth_engine_arch.php';
                $pdo = db();
                $candidates = get_guest_return_candidates($restId, 20);
                $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                $created = 0;
                foreach ($candidates as $c) {
                    $payload = json_encode([
                        'guest_contact' => (string)($c['guest_contact'] ?? ''),
                        'guest_name' => $c['guest_name'] ?? null,
                        'suggested_offer' => (string)($c['suggested_offer'] ?? ''),
                        'message' => (string)($c['suggested_message'] ?? ''),
                        'score' => (int)($c['score'] ?? 0),
                    ], JSON_UNESCAPED_UNICODE);
                    $title = 'Guest return: ' . (string)($c['guest_contact'] ?? '');
                    if (function_exists('growth_engine_suggestion_duplicate_exists') && growth_engine_suggestion_duplicate_exists($pdo, $restId, 'guest_return_offer', $payload, $title)) {
                        continue;
                    }
                    if ($hasStatus) {
                        $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'guest_return_offer', ?, ?, ?, 'medium', 'guest_return_engine', 'pending')");
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'guest_return_offer', ?, ?, ?)");
                    }
                    $stmt->execute([$restId, $title, 'Comeback offer draft. Review and send via CRM.', $payload]);
                    $created++;
                }
                if ($created > 0) {
                    $success = 'Created ' . $created . ' comeback draft(s).';
                } else {
                    $errors[] = 'No new drafts (all candidates already have recent drafts).';
                }
            }
        } elseif ($action === 'create_single_retention_draft') {
            $contact = trim((string)($_POST['guest_contact'] ?? ''));
            $message = trim((string)($_POST['message'] ?? 'We miss you! Come back this week and enjoy a special offer.'));
            $name = trim((string)($_POST['guest_name'] ?? ''));
            if ($contact !== '' && file_exists(__DIR__ . '/../../app/growth_engine_arch.php') && function_exists('db_table_exists') && db_table_exists('growth_engine_suggestions')) {
                require_once __DIR__ . '/../../app/growth_engine_arch.php';
                $pdo = db();
                $payload = json_encode(['guest_contact' => $contact, 'guest_name' => $name ?: null, 'message' => $message], JSON_UNESCAPED_UNICODE);
                $title = 'Retention draft for ' . $contact;
                if (function_exists('growth_engine_suggestion_duplicate_exists') && growth_engine_suggestion_duplicate_exists($pdo, $restId, 'crm_retention_draft', $payload, $title)) {
                    $errors[] = 'Retention draft for this guest was already created recently.';
                } else {
                    $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
                    if ($hasStatus) {
                        $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json, priority, source, status) VALUES (?, 'crm_retention_draft', ?, ?, ?, 'medium', 'guest_retention', 'pending')");
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO growth_engine_suggestions (restaurant_id, type, title, description, payload_json) VALUES (?, 'crm_retention_draft', ?, ?, ?)");
                    }
                    $stmt->execute([$restId, $title, 'Draft comeback message. Review and send via CRM.', $payload]);
                    $success = 'Retention suggestion draft created.';
                }
            }
        } elseif ($action === 'publish_feedback_drafts' && function_exists('publish_feedback_based_suggestions')) {
            $created = publish_feedback_based_suggestions($restId, 20);
            if ($created > 0) {
                $success = 'Созданы черновики из отзывов: ' . $created . '.';
            } else {
                $errors[] = 'Новых черновиков из отзывов не найдено (возможно, уже опубликованы).';
            }
        } elseif ($action === 'save_manual_return') {
            $guestId = (int)($_POST['guest_id'] ?? 0);
            $message = trim((string)($_POST['message_body'] ?? ''));
            $saveMode = trim((string)($_POST['save_mode'] ?? 'draft'));
            if ($guestId <= 0) {
                $errors[] = 'Некорректный гость.';
            } elseif ($message === '') {
                $errors[] = 'Введите текст сообщения.';
            } elseif (!in_array($saveMode, ['draft', 'ready'], true)) {
                $errors[] = 'Некорректное действие сохранения.';
            } elseif (!function_exists('crm_save_manual_return_message') || !crm_save_manual_return_message($restId, $guestId, $message, $saveMode)) {
                $errors[] = 'Не удалось сохранить (проверьте таблицу crm_outbox и права).';
            } else {
                $success = $saveMode === 'ready'
                    ? 'Сообщение сохранено как готово к ручной отправке (запись в outbox, авто-отправки нет).'
                    : 'Черновик сохранён. Просмотр: фильтр «Черновики» в блоке исходящих ниже.';
            }
        } elseif ($action === 'create_inactive_return_draft') {
            if (function_exists('crm_guest_return_enabled') && !crm_guest_return_enabled($restId)) {
                $errors[] = 'Возврат гостей отключён в настройках. Включите в «Настройки» → блок CRM.';
            }
            $guestId = (int)($_POST['guest_id'] ?? 0);
            if ($errors !== []) {
                // skip
            } elseif ($guestId <= 0) {
                $errors[] = 'Некорректный гость.';
            } else {
                $dashRow = null;
                foreach (crm_restaurant_guests_dashboard_rows($restId) as $gr) {
                    if ((int)($gr['id'] ?? 0) === $guestId) {
                        $dashRow = $gr;
                        break;
                    }
                }
                if ($dashRow === null) {
                    $errors[] = 'Гость не найден в этом ресторане.';
                } elseif (function_exists('crm_create_inactive_return_outbox_draft')) {
                    $res = crm_create_inactive_return_outbox_draft($restId, $dashRow);
                    if (!empty($res['ok'])) {
                        $success = 'Черновик возврата создан. Проверьте блок «Исходящие сообщения» (фильтр «Черновики»).';
                    } elseif (($res['reason'] ?? '') === 'duplicate') {
                        $errors[] = 'Уже есть черновик, готовая к отправке запись или ожидающая строка за последние 7 дней для этого гостя.';
                    } elseif (($res['reason'] ?? '') === 'not_inactive') {
                        $errors[] = 'Гость не в сегменте «давно не были» — черновик не создан.';
                    } else {
                        $errors[] = 'Не удалось создать черновик.';
                    }
                } else {
                    $errors[] = 'Функция недоступна.';
                }
            }
        } elseif ($action === 'bulk_inactive_return_drafts') {
            if (function_exists('crm_guest_return_enabled') && !crm_guest_return_enabled($restId)) {
                $errors[] = 'Возврат гостей отключён в настройках.';
            } elseif (function_exists('crm_bulk_create_inactive_return_drafts')) {
                $res = crm_bulk_create_inactive_return_drafts($restId, 50);
                $success = 'Создано черновиков: ' . (int)$res['created'] . '. Пропущено как дубли: ' . (int)$res['skipped_duplicate'] . '.';
                if ((int)$res['skipped_other'] > 0) {
                    $success .= ' Прочие пропуски: ' . (int)$res['skipped_other'] . '.';
                }
            } else {
                $errors[] = 'Функция недоступна.';
            }
        }
    }
}

$statusFilter = trim($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'processed', 'canceled', 'failed', 'draft', 'ready_manual'], true)) {
    $statusFilter = 'pending';
}

$statusFilterUiLabel = match ($statusFilter) {
    'pending' => 'Ожидается',
    'processed' => 'Отправлено (manual)',
    'canceled' => 'Отменено',
    'failed' => 'Ошибка',
    'draft' => 'Черновики',
    'ready_manual' => 'Готово к ручной отправке',
    default => $statusFilter,
};

if (is_demo_mode()) {
    $rows = array_filter(demo_crm_outbox(), function ($r) use ($statusFilter) {
        return ($r['status'] ?? '') === $statusFilter;
    });
    $rows = array_values($rows);
} else {
    $rows = [];
    try {
        $rows = crm_list_outbox($restId, $statusFilter, 200);
    } catch (Throwable $e) {
        error_log('RESTAURANT_CRM list rid=' . $rid . ' ' . $e->getMessage());
    }
}

$retentionSuggestions = [];
if (!is_demo_mode() && file_exists(__DIR__ . '/../../app/retention_suggestions.php')) {
    require_once __DIR__ . '/../../app/retention_suggestions.php';
    $retentionSuggestions = retention_suggestions($restId, 14, 8);
} elseif (is_demo_mode()) {
    $retentionSuggestions = [
        ['id' => 1, 'phone' => '+7 916 100-42-18', 'last_seen_at' => date('Y-m-d', strtotime('-18 days')), 'visits_count' => 4, 'suggestion' => 'Предложить бронь у окна и десерт в подарок'],
        ['id' => 2, 'phone' => '+7 903 221-55-90', 'last_seen_at' => date('Y-m-d', strtotime('-21 days')), 'visits_count' => 7, 'suggestion' => 'Напомнить о новых позициях в меню'],
        ['id' => 3, 'phone' => '+7 926 008-77-31', 'last_seen_at' => date('Y-m-d', strtotime('-25 days')), 'visits_count' => 2, 'suggestion' => 'Лимонад к первому заказу при возврате'],
    ];
}
if (file_exists(__DIR__ . '/../../app/return_prediction.php')) {
    require_once __DIR__ . '/../../app/return_prediction.php';
    foreach ($retentionSuggestions as &$g) {
        $pred = predict_guest_return(['visits_count' => (int)($g['visits_count'] ?? 0), 'last_seen_at' => $g['last_seen_at'] ?? null]);
        $g['return_label'] = $pred['label'];
        $g['return_reason'] = $pred['reason'];
    }
    unset($g);
}
if (is_demo_mode()) {
    $retentionSuggestions[0]['return_label'] = 'High';
    $retentionSuggestions[0]['return_reason'] = '4 визита; высокая вероятность возврата с оффером';
    $retentionSuggestions[1]['return_label'] = 'Medium';
    $retentionSuggestions[1]['return_reason'] = '7 визитов ранее; 21 день без визита';
    $retentionSuggestions[2]['return_label'] = 'Low';
    $retentionSuggestions[2]['return_reason'] = '25 дней без визита — отправьте напоминание';
}
$simpleRetentionOpps = [];
if (function_exists('get_retention_opportunities')) {
    $simpleRetentionOpps = get_retention_opportunities($restId);
}
$comebackCandidates = [];
if (file_exists(__DIR__ . '/../../app/guest_return_engine.php')) {
    require_once __DIR__ . '/../../app/guest_return_engine.php';
    $comebackCandidates = get_guest_return_candidates($restId, 30);
}
$retentionStats = [];
$guestSegments = [];
// Read-only preview; no writes on page load.
$feedbackBasedSuggestionsPreview = [];
if (!is_demo_mode() && function_exists('get_feedback_based_suggestions')) {
    $feedbackBasedSuggestionsPreview = get_feedback_based_suggestions($restId, 20);
}
// Read-only preview: drafts created from feedback->upsell->CRM return flow.
$feedbackUpsellCrmDrafts = [];
$retentionAttributionBySuggestion = [];
if (!is_demo_mode() && function_exists('db_table_exists') && db_table_exists('growth_engine_suggestions')) {
    try {
        $hasStatus = function_exists('db_column_exists') && db_column_exists('growth_engine_suggestions', 'status');
        $sql = "SELECT id, title, description, payload_json, created_at";
        if ($hasStatus) {
            $sql .= ", status";
        }
        $sql .= " FROM growth_engine_suggestions WHERE restaurant_id = ? AND type = 'crm_retention_with_offer'";
        $params = [$restId];
        if ($hasStatus) {
            $sql .= " AND status IN ('pending','accepted')";
        }
        $sql .= " ORDER BY created_at DESC LIMIT 20";
        $pdo = db();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $feedbackUpsellCrmDrafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $feedbackUpsellCrmDrafts = [];
    }
}
if (!is_demo_mode() && function_exists('get_retention_attribution')) {
    try {
        $att = get_retention_attribution($restId, 30);
        foreach ($att as $row) {
            $sid = (int)($row['suggestion_id'] ?? 0);
            if ($sid > 0) {
                $retentionAttributionBySuggestion[$sid] = $row;
            }
        }
    } catch (Throwable $e) {
        $retentionAttributionBySuggestion = [];
    }
}
if (function_exists('get_retention_stats')) {
    $retentionStats = get_retention_stats($restId);
}
if (function_exists('get_guest_segments_summary')) {
    $guestSegments = get_guest_segments_summary($restId);
}

$guestSearchQ = trim((string)($_GET['gq'] ?? ''));
$guestSegFilter = trim((string)($_GET['gseg'] ?? 'all'));
if (!in_array($guestSegFilter, ['all', 'new', 'active', 'inactive'], true)) {
    $guestSegFilter = 'all';
}
$historyGuestId = (int)($_GET['history'] ?? 0);
$composeGuestId = (int)($_GET['compose'] ?? 0);

$crmGuestsRowsRaw = [];
if (is_demo_mode()) {
    foreach (demo_crm_guests() as $dg) {
        $crmGuestsRowsRaw[] = [
            'id' => (int)$dg['id'],
            'phone' => (string)$dg['phone'],
            'visits_count' => (int)$dg['visits'],
            'last_seen_at' => $dg['last_visit'] . ' 12:00:00',
            'first_seen_at' => $dg['last_visit'] . ' 00:00:00',
            'order_count' => max(1, (int)$dg['visits']),
            'total_spent' => (float)$dg['total_spent'],
            'last_order_at' => $dg['last_visit'] . ' 18:00:00',
            'consent' => '1',
            'guest_display_name' => (string)$dg['name'],
        ];
    }
} else {
    $crmGuestsRowsRaw = function_exists('crm_restaurant_guests_dashboard_rows') ? crm_restaurant_guests_dashboard_rows($restId) : [];
}

$crmGuestStats = ['total' => 0, 'new' => 0, 'active' => 0, 'inactive' => 0, 'last30' => 0, 'avg_check_global' => null];
$sumAvgParts = 0.0;
$nAvgParts = 0;
$cut30 = strtotime('-30 days');
foreach ($crmGuestsRowsRaw as $gr) {
    $crmGuestStats['total']++;
    $segOne = function_exists('crm_guest_ui_segment') ? crm_guest_ui_segment($gr, $restId) : 'inactive';
    if ($segOne === 'new') {
        $crmGuestStats['new']++;
    } elseif ($segOne === 'active') {
        $crmGuestStats['active']++;
    } else {
        $crmGuestStats['inactive']++;
    }
    $lastRaw = $gr['last_seen_at'] ?? $gr['last_order_at'] ?? null;
    $lt = $lastRaw ? strtotime((string)$lastRaw) : 0;
    if ($lt >= $cut30) {
        $crmGuestStats['last30']++;
    }
    $oc = (int)($gr['order_count'] ?? 0);
    $ts = (float)($gr['total_spent'] ?? 0);
    if ($oc > 0 && $ts > 0) {
        $sumAvgParts += $ts / $oc;
        $nAvgParts++;
    }
}
if ($nAvgParts > 0) {
    $crmGuestStats['avg_check_global'] = round($sumAvgParts / $nAvgParts, 2);
}

$crmGuestsFiltered = [];
foreach ($crmGuestsRowsRaw as $gr) {
    $seg = function_exists('crm_guest_ui_segment') ? crm_guest_ui_segment($gr, $restId) : 'inactive';
    if ($guestSegFilter !== 'all' && $seg !== $guestSegFilter) {
        continue;
    }
    if ($guestSearchQ !== '') {
        $qn = mb_strtolower($guestSearchQ, 'UTF-8');
        $phone = mb_strtolower((string)($gr['phone'] ?? ''), 'UTF-8');
        $nm = mb_strtolower((string)($gr['guest_display_name'] ?? ''), 'UTF-8');
        if (mb_strpos($phone, $qn, 0, 'UTF-8') === false && ($nm === '' || mb_strpos($nm, $qn, 0, 'UTF-8') === false)) {
            continue;
        }
    }
    $oc = (int)($gr['order_count'] ?? 0);
    $ts = (float)($gr['total_spent'] ?? 0);
    $gr['ui_segment'] = $seg;
    $gr['avg_check'] = $oc > 0 ? round($ts / $oc, 2) : 0.0;
    $crmGuestsFiltered[] = $gr;
}

$historyOrders = [];
$historyGuestRow = null;
if ($historyGuestId > 0) {
    if (is_demo_mode()) {
        $okDemo = false;
        foreach ($crmGuestsRowsRaw as $gr) {
            if ((int)$gr['id'] === $historyGuestId) {
                $okDemo = true;
                $historyGuestRow = ['phone' => $gr['phone'], 'id' => $gr['id']];
                break;
            }
        }
        if ($okDemo) {
            $historyOrders = [
                ['id' => 900 + $historyGuestId, 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')), 'order_total' => 890, 'order_status' => 'done', 'payment_status' => 'paid'],
                ['id' => 800 + $historyGuestId, 'created_at' => date('Y-m-d H:i:s', strtotime('-14 days')), 'order_total' => 1200, 'order_status' => 'done', 'payment_status' => 'paid'],
            ];
        }
    } else {
        $historyGuestRow = crm_guest_lookup($restId, $historyGuestId);
        if ($historyGuestRow) {
            $historyOrders = crm_guest_orders_history($restId, $historyGuestId, 40);
        }
    }
}

$composeGuestRow = null;
if ($composeGuestId > 0) {
    if (is_demo_mode()) {
        foreach ($crmGuestsRowsRaw as $gr) {
            if ((int)$gr['id'] === $composeGuestId) {
                $composeGuestRow = ['id' => $gr['id'], 'phone' => $gr['phone'], 'visits_count' => $gr['visits_count']];
                break;
            }
        }
    } else {
        $composeGuestRow = crm_guest_lookup($restId, $composeGuestId);
    }
}

$crmTplNew = 'Спасибо за визит! Будем рады видеть вас снова.';
$crmTplInactive = 'Давно вас не было у нас. Возвращайтесь — будем рады новому визиту.';
$crmTplActive = 'Спасибо, что выбираете нас. Приходите снова — у нас есть для вас новые позиции в меню.';

$composeDefaultMsg = $crmTplActive;
if ($composeGuestRow) {
    foreach ($crmGuestsRowsRaw as $gr) {
        if ((int)($gr['id'] ?? 0) === $composeGuestId) {
            $sg = function_exists('crm_guest_ui_segment') ? crm_guest_ui_segment($gr, $restId) : 'inactive';
            $composeDefaultMsg = $sg === 'new' ? $crmTplNew : ($sg === 'active' ? $crmTplActive : $crmTplInactive);
            break;
        }
    }
}

$crmQueryBase = static function (array $extra) use ($guestSearchQ, $guestSegFilter, $statusFilter, $historyGuestId, $composeGuestId): string {
    $q = array_merge([
        'status' => $statusFilter,
        'gq' => $guestSearchQ !== '' ? $guestSearchQ : null,
        'gseg' => $guestSegFilter !== 'all' ? $guestSegFilter : null,
        'history' => $historyGuestId > 0 ? $historyGuestId : null,
        'compose' => $composeGuestId > 0 ? $composeGuestId : null,
    ], $extra);
    $q = array_filter($q, static fn ($v) => $v !== null && $v !== '');
    return $q === [] ? '/restaurant/crm.php' : ('/restaurant/crm.php?' . http_build_query($q));
};

$inactiveReturnGuests = function_exists('crm_guests_ready_for_inactive_return_list')
    ? crm_guests_ready_for_inactive_return_list($restId)
    : [];
$inactiveThresholdDays = function_exists('crm_inactive_visit_threshold_days')
    ? crm_inactive_visit_threshold_days($restId)
    : 14;
$crmGuestReturnEnabled = function_exists('crm_guest_return_enabled') ? crm_guest_return_enabled($restId) : true;
$inactiveReturnEligibleCount = 0;
foreach ($inactiveReturnGuests as $_ir) {
    if (!empty($_ir['can_generate'])) {
        $inactiveReturnEligibleCount++;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>CRM — <?= e($currentRestaurant['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="/assets/css/motion.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex overflow-x-hidden <?= is_demo_mode() ? 'demo-mode' : '' ?>">

<aside class="w-full md:w-[260px] shrink-0 bg-[#0f172a] border-b md:border-b-0 md:border-r border-slate-800/90 md:min-h-screen">
    <div class="p-5 md:p-6 md:sticky md:top-0 md:max-h-screen md:flex md:flex-col">
        <?= brand_restaurant_sidebar_header_html($currentRestaurant['name'] ?? '') ?>
        <nav class="flex flex-wrap md:flex-col gap-1 text-[15px] font-medium">
            <a href="/restaurant/dashboard.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors">Дашборд</a>
            <a href="/restaurant/menu_manage.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors">Меню</a>
            <a href="/restaurant/tables.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors">Столы</a>
            <a href="/restaurant/qr_codes.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors">QR-коды</a>
            <a href="/restaurant/orders.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors">Заказы</a>
            <a href="/restaurant/crm.php" class="px-3 py-2.5 rounded-xl text-white bg-white/10 border border-white/10 shadow-sm">Гости и CRM</a>
            <a href="/restaurant/crm_campaigns.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors text-sm">Кампании</a>
            <a href="/restaurant/settings.php" class="px-3 py-2.5 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-colors">Настройки</a>
        </nav>
        <div class="mt-6 md:mt-auto pt-4 md:pt-8 border-t border-slate-800/80 md:border-0">
            <a href="/logout.php" class="block px-3 py-2.5 rounded-xl text-sm text-slate-500 hover:text-red-300 hover:bg-red-500/10 transition-colors">Выйти</a>
        </div>
    </div>
</aside>

<main class="flex-1 min-w-0 p-4 md:p-6 overflow-x-hidden">
    <div class="max-w-6xl mx-auto space-y-6 page-enter">
        <?php if ($success): ?>
            <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/40 px-4 py-3.5 text-sm text-emerald-100" role="status"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="rounded-xl bg-red-500/10 border border-red-500/40 px-4 py-3.5 text-sm text-red-100 space-y-1" role="alert">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex items-center justify-center gap-2 text-sm text-amber-200">
            <span aria-hidden="true">⚠</span>
            <span>Demo environment — actions are simulated.</span>
        </div>
        <?php endif; ?>
        <?php if ($trialRequiresUpgrade): ?>
        <div class="rounded-2xl bg-red-500/10 border border-red-500/50 px-4 py-4 text-center">
            <p class="text-slate-100 font-medium mb-2">Доступ к CRM доступен после активации подписки</p>
            <a href="/restaurant/activate.php" class="inline-block px-4 py-2 rounded-xl bg-red-500/40 hover:bg-red-500/60 text-white text-sm font-medium">Активировать подписку</a>
        </div>
        <?php elseif ($trialInfo['is_trial'] && !$trialInfo['is_expired']): ?>
        <div class="rounded-2xl bg-sky-500/10 border border-sky-500/50 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm text-sky-100">Пробный период: осталось <?= (int)$trialInfo['days_left'] ?> дн.</span>
            <a href="/restaurant/activate.php" class="px-3 py-1.5 rounded-xl bg-sky-500/30 hover:bg-sky-500/50 text-sky-100 text-sm font-medium">Выбрать тариф</a>
        </div>
        <?php endif; ?>

        <?php if (!$crmEnabled): ?>
        <div class="rounded-2xl border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="status">
            <p class="font-medium">CRM-возврат гостей доступен на тарифе GROWTH</p>
            <p class="text-xs text-amber-200/80 mt-1">Подключите CRM, чтобы возвращать гостей и запускать кампании.</p>
            <a href="/owner/billing.php" class="inline-flex items-center mt-3 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф GROWTH</a>
        </div>
        <?php endif; ?>

        <?php if (!$trialRequiresUpgrade): ?>

        <section id="guests-return" class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 md:p-8 space-y-6 shadow-xl shadow-black/10">
            <header>
                <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight">Гости и возврат</h1>
                <p class="text-slate-400 text-sm md:text-base mt-1"><?= e($currentRestaurant['name'] ?? '') ?></p>
                <p class="text-xs text-slate-500 mt-2 max-w-2xl">База гостей из CRM и заказов этого ресторана. Сообщения не отправляются автоматически — только черновики и ручная отметка.</p>
                <p class="text-xs text-slate-600 mt-2">Порог «давно не был» для сегментов: <span class="text-slate-300 font-medium"><?= (int)$inactiveThresholdDays ?> дн.</span>
                    — <a href="/restaurant/settings.php#crm-return-settings" class="text-indigo-400 hover:text-indigo-300">изменить в настройках CRM</a></p>
            </header>

            <?php if (!$crmGuestReturnEnabled): ?>
            <div class="rounded-2xl border border-slate-700 bg-slate-900/50 px-4 py-5 text-sm text-slate-400">
                <p class="font-medium text-slate-200">Возврат гостей выключен</p>
                <p class="text-xs text-slate-500 mt-2 max-w-xl">Блок «Готовы к возврату» и массовые черновики по неактивным скрыты. База гостей и ручная подготовка сообщений ниже доступны.</p>
                <a href="/restaurant/settings.php#crm-return-settings" class="inline-flex mt-3 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold">Открыть настройки CRM</a>
            </div>
            <?php endif; ?>

            <?php if ($crmGuestReturnEnabled): ?>
            <div class="rounded-2xl border border-amber-500/25 bg-gradient-to-br from-amber-500/5 to-slate-900/40 p-5 md:p-6 space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Готовы к возврату</h2>
                        <p class="text-xs text-slate-500 mt-1 max-w-xl">
                            Гости в сегменте «давно не были» (нет визита <?= (int)$inactiveThresholdDays ?>+ дн.), с телефоном.
                            Черновик попадает в <code class="text-slate-400">crm_outbox</code> со статусом «черновик» — без авто-отправки.
                        </p>
                    </div>
                    <?php if (!is_demo_mode() && $inactiveReturnEligibleCount > 0): ?>
                        <form method="post" class="shrink-0">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="bulk_inactive_return_drafts">
                            <button type="submit" class="w-full lg:w-auto px-5 py-3 rounded-xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white text-sm font-bold shadow-lg shadow-amber-900/20">
                                Сформировать черновики всем подходящим (до 50)
                            </button>
                        </form>
                    <?php elseif (is_demo_mode()): ?>
                        <p class="text-xs text-amber-200/80 shrink-0">Демо: создание черновиков отключено.</p>
                    <?php endif; ?>
                </div>
                <?php if ($inactiveReturnGuests === []): ?>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-8 text-center">
                        <p class="text-slate-400 text-sm">Сейчас нет гостей, которых пора возвращать</p>
                        <p class="text-xs text-slate-600 mt-2">Когда появятся повторные гости без визита <?= (int)$inactiveThresholdDays ?>+ дней, они отобразятся здесь.</p>
                    </div>
                <?php else: ?>
                    <div class="hidden md:block overflow-x-auto rounded-xl border border-slate-800">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-slate-500 border-b border-slate-800">
                                    <th class="p-3">Гость</th>
                                    <th class="p-3">Телефон</th>
                                    <th class="p-3">Последний визит</th>
                                    <th class="p-3">Визиты</th>
                                    <th class="p-3">Заказы / сумма</th>
                                    <th class="p-3">Средний чек</th>
                                    <th class="p-3">Статус</th>
                                    <th class="p-3">Действие</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($inactiveReturnGuests as $ir):
                                $igid = (int)($ir['id'] ?? 0);
                                $oc = (int)($ir['order_count'] ?? 0);
                                $ts = (float)($ir['total_spent'] ?? 0);
                                $avg = $oc > 0 && $ts > 0 ? round($ts / $oc, 2) : null;
                                $lastAt = (string)($ir['last_seen_at'] ?? $ir['last_order_at'] ?? '');
                                $nm = trim((string)($ir['guest_display_name'] ?? ''));
                                $hasDraft = !empty($ir['recent_outbox']);
                                $outboxLink = $crmQueryBase(['status' => 'draft']) . '#crm-outbox';
                                ?>
                                <tr class="border-b border-slate-800/80">
                                    <td class="p-3 text-slate-200"><?= $nm !== '' ? e($nm) : '—' ?></td>
                                    <td class="p-3 text-slate-300"><?= e((string)($ir['phone'] ?? '')) ?></td>
                                    <td class="p-3 text-slate-400 text-xs"><?= $lastAt !== '' ? e($lastAt) : '—' ?></td>
                                    <td class="p-3 text-slate-300"><?= (int)($ir['visits_count'] ?? 0) ?></td>
                                    <td class="p-3 text-slate-400"><?= $oc ?> / <?= $ts > 0 ? e(number_format($ts, 0, '.', ' ')) . ' ₽' : '—' ?></td>
                                    <td class="p-3 text-slate-400"><?= $avg !== null ? e((string)$avg) . ' ₽' : '—' ?></td>
                                    <td class="p-3">
                                        <?php if ($hasDraft): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-lg bg-violet-500/15 text-violet-200 text-[11px] font-medium border border-violet-500/30">Черновик уже готов</span>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-500">Можно сформировать</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3">
                                        <?php if ($hasDraft): ?>
                                            <a href="<?= e($outboxLink) ?>" class="text-[11px] text-amber-400 hover:text-amber-300 font-medium">К исходящим</a>
                                        <?php elseif (!is_demo_mode()): ?>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="action" value="create_inactive_return_draft">
                                                <input type="hidden" name="guest_id" value="<?= $igid ?>">
                                                <button type="submit" class="text-[11px] px-3 py-1.5 rounded-lg bg-amber-600/80 hover:bg-amber-500 text-white font-semibold">Сформировать черновик</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="md:hidden space-y-3">
                        <?php foreach ($inactiveReturnGuests as $ir):
                            $igid = (int)($ir['id'] ?? 0);
                            $oc = (int)($ir['order_count'] ?? 0);
                            $ts = (float)($ir['total_spent'] ?? 0);
                            $avg = $oc > 0 && $ts > 0 ? round($ts / $oc, 2) : null;
                            $lastAt = (string)($ir['last_seen_at'] ?? $ir['last_order_at'] ?? '');
                            $nm = trim((string)($ir['guest_display_name'] ?? ''));
                            $hasDraft = !empty($ir['recent_outbox']);
                            $outboxLink = $crmQueryBase(['status' => 'draft']) . '#crm-outbox';
                            ?>
                            <div class="rounded-xl border border-slate-800 bg-slate-950/40 p-4 space-y-2">
                                <div class="flex justify-between gap-2">
                                    <span class="font-medium text-slate-100"><?= $nm !== '' ? e($nm) : 'Гость #' . $igid ?></span>
                                    <?php if ($hasDraft): ?>
                                        <span class="shrink-0 inline-flex px-2 py-0.5 rounded-lg bg-violet-500/15 text-violet-200 text-[10px] font-medium border border-violet-500/30">Черновик готов</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-sm text-slate-400"><?= e((string)($ir['phone'] ?? '')) ?></div>
                                <div class="text-xs text-slate-500">Последний визит: <?= $lastAt !== '' ? e($lastAt) : '—' ?> · Визитов: <?= (int)($ir['visits_count'] ?? 0) ?></div>
                                <div class="text-xs text-slate-500">Заказы: <?= $oc ?><?= $ts > 0 ? ' · ' . e(number_format($ts, 0, '.', ' ')) . ' ₽' : '' ?><?= $avg !== null ? ' · ср. ' . e((string)$avg) . ' ₽' : '' ?></div>
                                <div class="pt-2">
                                    <?php if ($hasDraft): ?>
                                        <a href="<?= e($outboxLink) ?>" class="block text-center py-2 rounded-xl bg-slate-800 text-amber-300 text-sm font-medium">К исходящим</a>
                                    <?php elseif (!is_demo_mode()): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="create_inactive_return_draft">
                                            <input type="hidden" name="guest_id" value="<?= $igid ?>">
                                            <button type="submit" class="w-full py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-bold">Сформировать черновик</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Всего гостей</div>
                    <div class="text-2xl font-bold text-white mt-1"><?= (int)$crmGuestStats['total'] ?></div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Новые</div>
                    <div class="text-2xl font-bold text-sky-300 mt-1"><?= (int)$crmGuestStats['new'] ?></div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Активные</div>
                    <div class="text-2xl font-bold text-emerald-300 mt-1"><?= (int)$crmGuestStats['active'] ?></div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Давно не были</div>
                    <div class="text-2xl font-bold text-amber-300 mt-1"><?= (int)$crmGuestStats['inactive'] ?></div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">За 30 дней</div>
                    <div class="text-2xl font-bold text-violet-300 mt-1"><?= (int)$crmGuestStats['last30'] ?></div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Средний чек*</div>
                    <div class="text-2xl font-bold text-white mt-1"><?= $crmGuestStats['avg_check_global'] !== null ? e((string)$crmGuestStats['avg_check_global']) . ' ₽' : '—' ?></div>
                    <div class="text-[10px] text-slate-600 mt-1">*по оплаченным заказам с guest_id</div>
                </div>
            </div>

            <form method="get" class="flex flex-col lg:flex-row gap-3 lg:items-end flex-wrap">
                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-slate-500 mb-1">Поиск (телефон / имя)</label>
                    <input type="search" name="gq" value="<?= e($guestSearchQ) ?>" placeholder="+7… или имя"
                           class="w-full min-h-[44px] rounded-xl bg-[#111827] border border-slate-700 px-4 py-3 text-base text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Сегмент</label>
                    <select name="gseg" class="rounded-xl bg-[#111827] border border-slate-700 px-4 py-3 text-sm text-slate-100 min-h-[44px] w-full sm:min-w-[160px] sm:w-auto focus:outline-none focus:ring-2 focus:ring-indigo-500/40">
                        <option value="all" <?= $guestSegFilter === 'all' ? 'selected' : '' ?>>Все</option>
                        <option value="new" <?= $guestSegFilter === 'new' ? 'selected' : '' ?>>Новые</option>
                        <option value="active" <?= $guestSegFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                        <option value="inactive" <?= $guestSegFilter === 'inactive' ? 'selected' : '' ?>>Давно не были</option>
                    </select>
                </div>
                <button type="submit" class="min-h-[44px] px-5 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white text-sm font-semibold shadow-md shadow-indigo-900/30 touch-manipulation w-full sm:w-auto">Применить</button>
                <?php if ($guestSearchQ !== '' || $guestSegFilter !== 'all'): ?>
                    <a href="<?= e($crmQueryBase([])) ?>" class="px-4 py-2.5 rounded-xl text-sm text-slate-400 hover:text-white border border-slate-700">Сбросить</a>
                <?php endif; ?>
            </form>

            <?php if ($historyGuestId > 0 && $historyGuestRow): ?>
                <div class="rounded-xl border border-indigo-500/35 bg-indigo-500/5 p-4 md:p-5 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-white">История заказов</h2>
                        <a href="<?= e($crmQueryBase(['history' => null])) ?>" class="text-sm text-indigo-300 hover:text-indigo-200">Закрыть</a>
                    </div>
                    <p class="text-sm text-slate-400">Телефон: <span class="text-slate-200"><?= e((string)($historyGuestRow['phone'] ?? '')) ?></span></p>
                    <?php if ($historyOrders === []): ?>
                        <p class="text-sm text-slate-500">Нет заказов с привязкой к этому гостю в этом ресторане.</p>
                    <?php else: ?>
                        <div class="hidden md:block overflow-x-auto rounded-xl border border-slate-800">
                            <table class="w-full text-sm min-w-[640px]">
                                <thead><tr class="text-left text-slate-500 border-b border-slate-800">
                                    <th class="p-3">Дата</th><th class="p-3">№ заказа</th><th class="p-3">Сумма</th><th class="p-3">Блюда</th><th class="p-3">Статус</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($historyOrders as $ho):
                                    $oid = (int)($ho['id'] ?? 0);
                                    $itemsLine = (!is_demo_mode() && function_exists('crm_order_items_line')) ? crm_order_items_line($restId, $oid, 5) : '';
                                    ?>
                                    <tr class="border-b border-slate-800/80">
                                        <td class="p-3 text-slate-300"><?= e((string)($ho['created_at'] ?? '')) ?></td>
                                        <td class="p-3 text-slate-400">#<?= $oid ?></td>
                                        <td class="p-3 text-emerald-300 font-semibold"><?= e(number_format((float)($ho['order_total'] ?? 0), 0, '.', ' ')) ?> ₽</td>
                                        <td class="p-3 text-slate-400 text-xs max-w-xs"><?= $itemsLine !== '' ? e($itemsLine) : '—' ?></td>
                                        <td class="p-3 text-xs text-slate-500"><?= e((string)($ho['payment_status'] ?? '')) ?> / <?= e((string)($ho['order_status'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="md:hidden space-y-3">
                            <?php foreach ($historyOrders as $ho):
                                $oid = (int)($ho['id'] ?? 0);
                                $itemsLine = (!is_demo_mode() && function_exists('crm_order_items_line')) ? crm_order_items_line($restId, $oid, 5) : '';
                                ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 space-y-2">
                                    <div class="flex justify-between gap-2 items-start">
                                        <span class="text-slate-400 text-xs"><?= e((string)($ho['created_at'] ?? '')) ?></span>
                                        <span class="text-emerald-300 font-bold tabular-nums"><?= e(number_format((float)($ho['order_total'] ?? 0), 0, '.', ' ')) ?> ₽</span>
                                    </div>
                                    <div class="text-sm text-slate-200 font-medium">Заказ #<?= $oid ?></div>
                                    <?php if ($itemsLine !== ''): ?>
                                        <p class="text-xs text-slate-500 leading-relaxed break-words"><?= e($itemsLine) ?></p>
                                    <?php endif; ?>
                                    <p class="text-xs text-slate-500"><?= e((string)($ho['payment_status'] ?? '')) ?> · <?= e((string)($ho['order_status'] ?? '')) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($composeGuestId > 0 && $composeGuestRow): ?>
                <div class="rounded-xl border border-emerald-500/35 bg-emerald-500/5 p-4 md:p-5 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold text-white">Подготовить сообщение</h2>
                        <a href="<?= e($crmQueryBase(['compose' => null])) ?>" class="text-sm text-emerald-300 hover:text-emerald-200">Закрыть</a>
                    </div>
                    <p class="text-xs text-slate-500">Текст сохраняется в <code class="text-slate-400">crm_outbox</code> со статусом «черновик» или «готово к ручной отправке». Авто-рассылки нет.</p>
                    <form method="post" class="space-y-4">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="save_manual_return">
                        <input type="hidden" name="guest_id" value="<?= (int)$composeGuestRow['id'] ?>">
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Телефон гостя</label>
                            <input type="text" readonly value="<?= e((string)($composeGuestRow['phone'] ?? '')) ?>"
                                   class="w-full rounded-xl bg-slate-950 border border-slate-700 px-4 py-2.5 text-sm text-slate-300">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Шаблон</label>
                            <select id="crm-msg-template" class="w-full rounded-xl bg-[#111827] border border-slate-700 px-4 py-2.5 text-sm text-slate-100 focus:ring-2 focus:ring-indigo-500/40">
                                <option value="new"><?= e($crmTplNew) ?></option>
                                <option value="inactive"><?= e($crmTplInactive) ?></option>
                                <option value="active"><?= e($crmTplActive) ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 mb-1">Текст сообщения</label>
                            <textarea name="message_body" id="crm-msg-body" rows="5" required
                                      class="w-full rounded-xl bg-[#111827] border border-slate-700 px-4 py-3 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"><?= e($composeDefaultMsg) ?></textarea>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button type="submit" name="save_mode" value="draft" class="px-5 py-3 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-600 text-sm font-semibold text-slate-100">Сохранить как черновик</button>
                            <button type="submit" name="save_mode" value="ready" class="px-5 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-sm font-bold text-white shadow-lg shadow-emerald-900/30">Готово к ручной отправке</button>
                        </div>
                    </form>
                    <script>
                    (function () {
                        var sel = document.getElementById('crm-msg-template');
                        var body = document.getElementById('crm-msg-body');
                        if (!sel || !body) return;
                        var map = { new: <?= json_encode($crmTplNew, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, inactive: <?= json_encode($crmTplInactive, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, active: <?= json_encode($crmTplActive, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?> };
                        sel.addEventListener('change', function () {
                            var v = map[sel.value];
                            if (v) body.value = v;
                        });
                    })();
                    </script>
                </div>
            <?php endif; ?>

            <?php if ($crmGuestsFiltered === []): ?>
                <div class="rounded-2xl border border-dashed border-slate-600/50 bg-[#111827]/40 px-6 py-14 text-center space-y-3">
                    <h3 class="text-lg font-bold text-white">Гостей пока нет</h3>
                    <p class="text-sm text-slate-500 max-w-md mx-auto">Когда гости оставят телефон в заказе (и будет согласие), они появятся в CRM. Заказы с привязкой guest_id дадут суммы и историю.</p>
                </div>
            <?php else: ?>
                <div class="hidden md:block overflow-x-auto rounded-xl border border-slate-800">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-800">
                                <th class="p-3">Имя</th>
                                <th class="p-3">Телефон</th>
                                <th class="p-3">Последний визит</th>
                                <th class="p-3">Визиты</th>
                                <th class="p-3">Заказы</th>
                                <th class="p-3">Сумма</th>
                                <th class="p-3">Средний чек</th>
                                <th class="p-3">Статус</th>
                                <th class="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($crmGuestsFiltered as $g):
                            $gid = (int)$g['id'];
                            $seg = (string)($g['ui_segment'] ?? 'inactive');
                            $segLabel = $seg === 'new' ? 'Новый' : ($seg === 'active' ? 'Активный' : 'Давно не был');
                            $segClass = $seg === 'new' ? 'text-sky-300' : ($seg === 'active' ? 'text-emerald-300' : 'text-amber-300');
                            $lastD = $g['last_seen_at'] ?? $g['last_order_at'] ?? null;
                            $nm = trim((string)($g['guest_display_name'] ?? ''));
                            ?>
                            <tr class="border-b border-slate-800/80 hover:bg-slate-800/20">
                                <td class="p-3 text-slate-200"><?= $nm !== '' ? e($nm) : '—' ?></td>
                                <td class="p-3 text-slate-300"><?= e((string)($g['phone'] ?? '')) ?></td>
                                <td class="p-3 text-slate-400"><?= $lastD ? e(date('d.m.Y H:i', strtotime((string)$lastD))) : '—' ?></td>
                                <td class="p-3 text-slate-300"><?= (int)($g['visits_count'] ?? 0) ?></td>
                                <td class="p-3 text-slate-300"><?= (int)($g['order_count'] ?? 0) ?></td>
                                <td class="p-3 text-emerald-300 font-medium"><?= e(number_format((float)($g['total_spent'] ?? 0), 0, '.', ' ')) ?> ₽</td>
                                <td class="p-3 text-slate-400"><?= (float)($g['avg_check'] ?? 0) > 0 ? e(number_format((float)$g['avg_check'], 0, '.', ' ')) . ' ₽' : '—' ?></td>
                                <td class="p-3"><span class="text-xs font-semibold <?= e($segClass) ?>"><?= e($segLabel) ?></span></td>
                                <td class="p-3 whitespace-nowrap space-x-2">
                                    <a href="<?= e($crmQueryBase(['history' => $gid, 'compose' => null])) ?>#guests-return" class="text-indigo-400 hover:text-indigo-300 text-xs font-medium">История</a>
                                    <a href="<?= e($crmQueryBase(['compose' => $gid, 'history' => null])) ?>#guests-return" class="text-emerald-400 hover:text-emerald-300 text-xs font-medium">Сообщение</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="md:hidden space-y-3">
                    <?php foreach ($crmGuestsFiltered as $g):
                        $gid = (int)$g['id'];
                        $seg = (string)($g['ui_segment'] ?? 'inactive');
                        $segLabel = $seg === 'new' ? 'Новый' : ($seg === 'active' ? 'Активный' : 'Давно не был');
                        $lastD = $g['last_seen_at'] ?? $g['last_order_at'] ?? null;
                        $nm = trim((string)($g['guest_display_name'] ?? ''));
                        ?>
                        <div class="rounded-xl border border-slate-700 bg-[#111827] p-4 space-y-2">
                            <div class="font-semibold text-white"><?= $nm !== '' ? e($nm) : 'Гость' ?></div>
                            <div class="text-sm text-slate-400"><?= e((string)($g['phone'] ?? '')) ?></div>
                            <div class="text-xs text-slate-500">Последний визит: <?= $lastD ? e(date('d.m.Y', strtotime((string)$lastD))) : '—' ?></div>
                            <div class="text-xs text-slate-400">Визиты: <?= (int)($g['visits_count'] ?? 0) ?> · Заказы: <?= (int)($g['order_count'] ?? 0) ?> · <?= e(number_format((float)($g['total_spent'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                            <div class="text-xs font-semibold <?= $seg === 'new' ? 'text-sky-300' : ($seg === 'active' ? 'text-emerald-300' : 'text-amber-300') ?>"><?= e($segLabel) ?></div>
                            <div class="flex gap-2 pt-2">
                                <a href="<?= e($crmQueryBase(['history' => $gid, 'compose' => null])) ?>#guests-return" class="flex-1 text-center px-3 py-2 rounded-xl bg-slate-800 text-xs font-medium text-slate-200">История</a>
                                <a href="<?= e($crmQueryBase(['compose' => $gid, 'history' => null])) ?>#guests-return" class="flex-1 text-center px-3 py-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 text-xs font-bold text-white">Сообщение</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!is_demo_mode()): ?>
        <section id="feedback-suggestions" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Suggestions from feedback</h3>
            <p class="text-xs text-slate-500 mb-4">Черновики на основе отзывов гостей. Авто-отправка отключена.</p>
            <?php if (empty($feedbackBasedSuggestionsPreview)): ?>
                <div class="text-sm text-slate-500">Нет новых предложений по отзывам.</div>
            <?php else: ?>
                <ul class="space-y-2">
                    <?php foreach (array_slice($feedbackBasedSuggestionsPreview, 0, 8) as $s): ?>
                        <?php
                        $payload = json_decode((string)($s['payload_json'] ?? '{}'), true);
                        $rating = (int)($payload['rating'] ?? 0);
                        $commentPreview = trim((string)($payload['comment_preview'] ?? ''));
                        $sType = (string)($s['type'] ?? '');
                        $contactAvailable = !empty($payload['contact_available']);
                        $tone = $rating <= 2 ? 'text-red-300' : 'text-emerald-300';
                        ?>
                        <li class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-slate-200 font-medium"><?= e((string)($s['title'] ?? 'Feedback suggestion')) ?></div>
                                <span class="text-xs <?= e($tone) ?>"><?= $rating > 0 ? e((string)$rating) . '★' : '' ?></span>
                            </div>
                            <div class="text-xs text-slate-400 mt-0.5"><?= e((string)($s['description'] ?? '')) ?></div>
                            <?php if ($commentPreview !== ''): ?>
                                <div class="text-xs text-slate-500 mt-1">Комментарий: <?= e($commentPreview) ?></div>
                            <?php endif; ?>
                            <?php if (!$contactAvailable && ($sType === 'feedback_recovery_draft' || $sType === 'feedback_positive_return_draft')): ?>
                                <div class="text-[11px] text-amber-300 mt-1">Контакт гостя не найден — создаётся только internal follow-up.</div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($crmEnabled): ?>
                <form method="post" class="mt-4 flex justify-end">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="publish_feedback_drafts">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium btn-motion">
                        Создать черновики из отзывов
                    </button>
                </form>
                <?php else: ?>
                <p class="mt-4 text-right text-xs text-slate-500">CRM доступен на тарифе GROWTH. <a href="/owner/billing.php" class="text-amber-400 hover:underline">Перейти на тариф</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($feedbackUpsellCrmDrafts)): ?>
        <section id="feedback-upsell-drafts" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">CRM draft: feedback->upsell offers</h3>
            <p class="text-xs text-slate-500 mb-4">Черновики CRM, созданные после принятия upsell-предложений из отзывов (без авто-отправки).</p>
            <ul class="space-y-3">
                <?php foreach (array_slice($feedbackUpsellCrmDrafts, 0, 10) as $d): ?>
                    <?php
                        $pl = json_decode((string)($d['payload_json'] ?? '{}'), true);
                        $msg = (string)($pl['message_text'] ?? '');
                        $reason = (string)($pl['reason'] ?? '');
                        $segmentRaw = strtolower(trim((string)($pl['retention_segment'] ?? '')));
                        if ($segmentRaw === '' && $reason !== '') {
                            if ($reason === 'low_rating_recovery') {
                                $segmentRaw = 'low';
                            } elseif ($reason === 'experience_improve') {
                                $segmentRaw = 'neutral';
                            } elseif ($reason === 'loyal_guest_return') {
                                $segmentRaw = 'high';
                            }
                        }
                        $segmentLabel = $segmentRaw === 'low' ? 'LOW' : ($segmentRaw === 'neutral' ? 'NEUTRAL' : ($segmentRaw === 'high' ? 'HIGH' : '—'));
                        $estimatedBonus = isset($pl['recommended_bonus_points']) ? max(0, (float)$pl['recommended_bonus_points']) : 0.0;
                        $incentive = $pl['incentive_text'] ?? null;
                        $expirationHint = (string)($pl['expiration_hint'] ?? '');
                        $status = (string)($d['status'] ?? 'pending');
                        $sid = (int)($d['id'] ?? 0);
                        $attr = $retentionAttributionBySuggestion[$sid] ?? null;
                        $items = $pl['suggested_items'] ?? [];
                        if (!is_array($items)) $items = [];
                        $itemNames = [];
                        foreach ($items as $it) {
                            if (is_array($it) && !empty($it['name'])) $itemNames[] = (string)$it['name'];
                        }
                        $itemNamesStr = $itemNames !== [] ? implode(', ', array_slice($itemNames, 0, 2)) : '—';
                        $statusBadge = '⏳ Ожидается';
                        if ($status === 'accepted') {
                            $returned = !empty($attr['returned']);
                            $expiresAt = (string)($attr['expires_at'] ?? ($pl['expires_at'] ?? ''));
                            $isExpired = ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time());
                            if ($returned) {
                                $statusBadge = '✅ Вернулся (' . number_format((float)($attr['return_revenue'] ?? 0), 0, '.', ' ') . ' ₽';
                                if (isset($attr['days_to_return']) && $attr['days_to_return'] !== null) {
                                    $statusBadge .= ' через ' . (int)$attr['days_to_return'] . ' дн.';
                                }
                                $statusBadge .= ')';
                            } elseif ($isExpired) {
                                $statusBadge = '❌ Не вернулся';
                            } else {
                                $statusBadge = '⏳ Ожидается';
                            }
                        }
                    ?>
                    <li class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-slate-200 font-medium">
                                <?= e($d['title'] ?? 'CRM draft') ?>
                                <div class="text-xs text-slate-500 mt-1">Предложить: <?= e($itemNamesStr) ?></div>
                                <?php if ($reason !== ''): ?>
                                    <div class="text-[11px] text-slate-500 mt-1">Причина: <?= e($reason) ?></div>
                                <?php endif; ?>
                                <?php if ($segmentLabel !== '—'): ?>
                                    <div class="text-[11px] text-slate-500 mt-1">Сегмент: <?= e($segmentLabel) ?></div>
                                <?php endif; ?>
                                <?php if ($status === 'accepted'): ?>
                                    <div class="text-[11px] text-slate-300 mt-1"><?= e($statusBadge) ?></div>
                                    <?php if (is_array($attr)): ?>
                                        <div class="text-[11px] text-slate-400 mt-1">
                                            Доход: <?= number_format((float)($attr['return_revenue'] ?? 0), 0, '.', ' ') ?> ₽
                                            <?php if (isset($attr['days_to_return']) && $attr['days_to_return'] !== null): ?>
                                                · Через: <?= (int)$attr['days_to_return'] ?> дней
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($estimatedBonus > 0): ?>
                                        <div class="text-[11px] text-slate-400 mt-1">Оценочный бонус: <?= number_format($estimatedBonus, 0, '.', ' ') ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-slate-500 whitespace-nowrap">
                                <?= !empty($d['created_at']) ? e(date('M j, Y H:i', strtotime((string)$d['created_at']))) : '—' ?>
                            </div>
                        </div>
                        <?php if ($msg !== ''): ?>
                            <div class="text-xs text-slate-300 mt-2"><?= e($msg) ?></div>
                        <?php endif; ?>
                        <?php if ($expirationHint !== ''): ?>
                            <div class="text-[11px] text-sky-300 mt-2"><?= e($expirationHint) ?></div>
                        <?php endif; ?>
                        <?php if (is_string($incentive) && trim($incentive) !== ''): ?>
                            <div class="text-[11px] text-amber-300 mt-2"><?= e($incentive) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if (!empty($retentionSuggestions)): ?>
        <section class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Guest return opportunities</h3>
            <p class="text-xs text-slate-500 mb-4">Guests who haven’t visited in 14+ days. Send a comeback offer to bring them back.</p>
            <ul class="space-y-3">
                <?php foreach (array_slice($retentionSuggestions, 0, 8) as $g): ?>
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm">
                        <div>
                            <span class="text-slate-200"><?= e($g['phone']) ?></span>
                            <?php $rl = $g['return_label'] ?? ''; $badgeClass = $rl === 'High' ? 'bg-emerald-500/20 text-emerald-300' : ($rl === 'Medium' ? 'bg-amber-500/20 text-amber-300' : 'bg-slate-600 text-slate-400'); ?>
                            <?php if ($rl): ?><span class="ml-2 px-2 py-0.5 rounded text-[10px] font-medium <?= $badgeClass ?>"><?= e($rl) ?></span><?php endif; ?>
                            <span class="text-slate-500 text-xs ml-2 block mt-0.5">Last visit: <?= $g['last_seen_at'] ? e(date('M j, Y', strtotime($g['last_seen_at']))) : '—' ?> · <?= (int)$g['visits_count'] ?> visits</span>
                        </div>
                        <span class="text-slate-400 text-xs"><?= e($g['suggestion']) ?></span>
                        <a href="/restaurant/guest_view.php?guest_id=<?= (int)($g['id']) ?>" class="text-[11px] text-indigo-400 hover:underline">View timeline</a>
                        <?php if ($crmEnabled && !is_demo_mode() && function_exists('crm_schedule_comeback')): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="schedule_comeback">
                            <input type="hidden" name="guest_id" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium btn-motion">Create comeback message</button>
                        </form>
                        <?php elseif (!$crmEnabled): ?>
                        <span class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-500 text-xs cursor-not-allowed" title="CRM на тарифе GROWTH">Create comeback message</span>
                        <?php else: ?>
                        <a href="/restaurant/crm_campaigns.php" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs font-medium btn-motion">Create comeback message</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if (!empty($guestSegments) || !empty($simpleRetentionOpps)): ?>
        <section class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Retention opportunities</h3>
            <p class="text-xs text-slate-500 mb-2">
                Guests who have visited before but haven&apos;t been back recently. Use the draft message as a starting point for a manual CRM campaign.
            </p>
            <?php if (!empty($guestSegments)): ?>
            <div class="flex flex-wrap gap-3 mb-4 text-xs">
                <span class="rounded-lg bg-slate-800/80 px-2 py-1 text-slate-300">New: <?= (int)($guestSegments['new_guest'] ?? 0) ?></span>
                <span class="rounded-lg bg-slate-800/80 px-2 py-1 text-slate-300">Returning: <?= (int)($guestSegments['returning_guest'] ?? 0) ?></span>
                <span class="rounded-lg bg-amber-500/20 px-2 py-1 text-amber-300">Inactive: <?= (int)($guestSegments['inactive_guest'] ?? 0) ?></span>
                <span class="rounded-lg bg-emerald-500/20 px-2 py-1 text-emerald-300">Loyal: <?= (int)($guestSegments['loyal_guest'] ?? 0) ?></span>
            </div>
            <?php endif; ?>
            <ul class="space-y-3">
                <?php foreach (array_slice($simpleRetentionOpps, 0, 20) as $g): ?>
                    <li class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm space-y-1">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div>
                                <div class="text-slate-200">
                                    <?= e($g['guest_contact'] ?? '') ?>
                                    <?php if (!empty($g['guest_name'])): ?>
                                        <span class="text-slate-400 text-xs ml-1">(<?= e($g['guest_name']) ?>)</span>
                                    <?php endif; ?>
                                    <?php $seg = $g['segment'] ?? 'inactive_guest'; ?>
                                    <span class="ml-2 px-2 py-0.5 rounded text-[10px] font-medium bg-amber-500/20 text-amber-300"><?= e(str_replace('_', ' ', ucfirst($seg))) ?></span>
                                </div>
                                <div class="text-slate-500 text-xs mt-0.5">
                                    Last visit: <?= !empty($g['last_visit']) ? e(date('Y-m-d', strtotime($g['last_visit']))) : '—' ?>
                                    · <?= (int)($g['visits_count'] ?? 0) ?> visits total
                                    <?php $inact = (int)($g['inactivity_days'] ?? 0); if ($inact > 0): ?>
                                        · <span class="text-amber-400">Inactive <?= $inact ?> days</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="mt-1">
                            <div class="text-[11px] text-slate-400 uppercase tracking-wide mb-0.5">Suggested message draft</div>
                            <div class="text-xs text-slate-100 bg-slate-900/80 border border-slate-800 rounded-lg px-2 py-1.5">
                                <?= e($g['message'] ?? 'We miss you! Come back this week and enjoy a special offer.') ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($simpleRetentionOpps) && function_exists('db_table_exists') && db_table_exists('growth_engine_suggestions')): ?>
            <?php if ($crmEnabled): ?>
            <form method="post" class="mt-4 flex justify-end">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="create_retention_drafts">
                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium btn-motion">
                    Create CRM retention drafts
                </button>
            </form>
            <?php else: ?>
            <p class="mt-4 text-right text-xs text-slate-500">CRM доступен на тарифе GROWTH. <a href="/owner/billing.php" class="text-amber-400 hover:underline">Перейти на тариф</a></p>
            <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($comebackCandidates)): ?>
        <section id="comeback-candidates" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">High-value comeback candidates</h3>
            <p class="text-xs text-slate-500 mb-4">Guests who have not returned in 14+ days (visits ≥ 2). Create a draft or retention suggestion — no auto-send.</p>
            <ul class="space-y-3">
                <?php foreach (array_slice($comebackCandidates, 0, 15) as $c): ?>
                    <li class="rounded-xl border border-slate-800 bg-slate-950/60 px-3 py-2 text-sm space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <span class="text-slate-200"><?= e($c['guest_contact']) ?></span>
                                <?php if (!empty($c['guest_name'])): ?><span class="text-slate-400 text-xs ml-1">(<?= e($c['guest_name']) ?>)</span><?php endif; ?>
                                <span class="ml-2 px-2 py-0.5 rounded text-[10px] font-medium bg-amber-500/20 text-amber-300">Score <?= (int)$c['score'] ?></span>
                                <span class="text-slate-500 text-xs block mt-0.5">Last visit: <?= !empty($c['last_visit']) ? e(date('Y-m-d', strtotime($c['last_visit']))) : '—' ?> · <?= (int)($c['visits_count'] ?? 0) ?> visits · avg <?= number_format((float)($c['avg_check'] ?? 0), 0) ?> ₽</span>
                            </div>
                            <div class="text-xs text-slate-400"><?= e($c['suggested_offer']) ?></div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="js-preview-message px-2 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-xs text-slate-200" data-message="<?= e($c['suggested_message']) ?>">Preview message</button>
                            <?php if ($crmEnabled && !is_demo_mode()): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="create_comeback_draft">
                                <input type="hidden" name="guest_contact" value="<?= e($c['guest_contact']) ?>">
                                <input type="hidden" name="guest_name" value="<?= e($c['guest_name'] ?? '') ?>">
                                <input type="hidden" name="suggested_offer" value="<?= e($c['suggested_offer']) ?>">
                                <input type="hidden" name="suggested_message" value="<?= e($c['suggested_message']) ?>">
                                <input type="hidden" name="score" value="<?= (int)$c['score'] ?>">
                                <button type="submit" class="px-2 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-xs text-white">Create comeback draft</button>
                            </form>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="create_single_retention_draft">
                                <input type="hidden" name="guest_contact" value="<?= e($c['guest_contact']) ?>">
                                <input type="hidden" name="guest_name" value="<?= e($c['guest_name'] ?? '') ?>">
                                <input type="hidden" name="message" value="<?= e($c['suggested_message']) ?>">
                                <button type="submit" class="px-2 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-xs text-slate-200">Create retention suggestion</button>
                            </form>
                            <?php elseif (!$crmEnabled): ?>
                            <span class="text-xs text-slate-500" title="CRM на тарифе GROWTH">Создание черновиков — на тарифе GROWTH</span>
                            <?php else: ?>
                            <span class="text-xs text-slate-500">Demo — drafts disabled</span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($crmEnabled && !is_demo_mode() && count($comebackCandidates) > 0): ?>
            <form method="post" class="mt-4 flex justify-end">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="create_guest_return_drafts">
                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium btn-motion">Create comeback drafts (batch)</button>
            </form>
            <?php elseif (!$crmEnabled && count($comebackCandidates) > 0): ?>
            <p class="mt-4 text-right text-xs text-slate-500">CRM доступен на тарифе GROWTH. <a href="/owner/billing.php" class="text-amber-400 hover:underline">Перейти на тариф</a></p>
            <?php endif; ?>
        </section>
        <div id="preview-message-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true">
            <div class="bg-slate-900 border border-slate-700 rounded-2xl p-4 max-w-md w-full shadow-xl">
                <div class="text-sm font-semibold text-slate-200 mb-2">Suggested message</div>
                <p id="preview-message-text" class="text-sm text-slate-300 mb-4"></p>
                <button type="button" id="preview-message-close" class="px-3 py-1.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs">Close</button>
            </div>
        </div>
        <script>
        (function(){
            var btn = document.querySelectorAll('.js-preview-message');
            var modal = document.getElementById('preview-message-modal');
            var text = document.getElementById('preview-message-text');
            var closeBtn = document.getElementById('preview-message-close');
            if (!modal || !text) return;
            btn.forEach(function(b){ b.addEventListener('click', function(){ text.textContent = this.getAttribute('data-message') || ''; modal.classList.remove('hidden'); }); });
            if (closeBtn) closeBtn.addEventListener('click', function(){ modal.classList.add('hidden'); });
            modal.addEventListener('click', function(e){ if (e.target === modal) modal.classList.add('hidden'); });
        })();
        </script>
        <?php endif; ?>

        <header id="crm-outbox" class="section reveal scroll-mt-24">
            <h2 class="text-2xl font-bold mb-1">Исходящие сообщения</h2>
            <p class="text-xs text-slate-500">Очередь и черновики (stub / manual — без авто-SMS и WhatsApp).</p>
        </header>

        <div class="flex flex-wrap gap-2 mb-3">
            <?php
            $outTab = static function (string $st, string $label) use ($statusFilter, $crmQueryBase): void {
                $href = $crmQueryBase(['status' => $st]);
                $on = $statusFilter === $st;
                $cls = $on ? 'bg-slate-700 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200';
                echo '<a href="' . e($href) . '" class="px-3 py-1.5 rounded-xl text-xs font-medium ' . $cls . '">' . e($label) . '</a>';
            };
            $outTab('pending', 'Ожидается');
            $outTab('draft', 'Черновики');
            $outTab('ready_manual', 'Готово к ручной отправке');
            $outTab('processed', 'Отправлено (manual)');
            $outTab('canceled', 'Отменено');
            $outTab('failed', 'Ошибка');
            ?>
        </div>

        <section class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon mx-auto text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <div class="empty-state-title">Нет сообщений</div>
                    <div class="empty-state-text">Нет записей со статусом «<?= e($statusFilterUiLabel) ?>».</div>
                    <?php if ($crmEnabled): ?><a href="/restaurant/crm_campaigns.php" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium btn-motion">Создать кампанию</a><?php else: ?><a href="/owner/billing.php" class="inline-block px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Перейти на тариф GROWTH</a><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-400 border-b border-slate-700">
                                <th class="pb-2 pr-2">Дата</th>
                                <th class="pb-2 pr-2">Телефон</th>
                                <th class="pb-2 pr-2">Сообщение</th>
                                <th class="pb-2 pr-2">Блюда</th>
                                <th class="pb-2 pr-2">Причина</th>
                                <th class="pb-2 pr-2">Статус</th>
                                <th class="pb-2">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr class="table-row-motion border-b border-slate-800/80">
                                    <?php
                                        $payload = json_decode((string)($r['payload_json'] ?? '{}'), true);
                                        if (!is_array($payload)) $payload = [];
                                        $msgText = (string)($payload['text'] ?? '');
                                        $reason  = (string)($payload['reason'] ?? ($payload['retention_reason'] ?? ($payload['cause'] ?? '')));

                                        $suggested = $payload['suggested_items'] ?? ($payload['suggested_dishes'] ?? ($payload['suggested_item_names'] ?? null));
                                        $suggestedNames = [];
                                        if (is_array($suggested)) {
                                            foreach ($suggested as $it) {
                                                if (is_string($it) && trim($it) !== '') {
                                                    $suggestedNames[] = $it;
                                                } elseif (is_array($it) && !empty($it['name'])) {
                                                    $suggestedNames[] = (string)$it['name'];
                                                }
                                            }
                                        } elseif (is_string($suggested) && trim($suggested) !== '') {
                                            $suggestedNames[] = $suggested;
                                        }
                                        $suggestedStr = $suggestedNames !== [] ? implode(', ', array_slice($suggestedNames, 0, 4)) : '—';

                                        $statusDb = (string)($r['status'] ?? 'pending');
                                        $statusUi = match ($statusDb) {
                                            'pending' => 'Ожидается',
                                            'draft' => 'Черновик',
                                            'ready_manual' => 'Готово к ручной отправке',
                                            'processed' => 'Отправлено (manual)',
                                            'canceled' => 'Отменено',
                                            'failed' => 'Ошибка',
                                            default => $statusDb,
                                        };
                                        $showOutboxQueueActions = $crmEnabled
                                            || in_array($statusDb, ['draft', 'ready_manual'], true)
                                            || (($r['template'] ?? '') === 'manual_return');
                                        $outboxId = (int)($r['id'] ?? 0);
                                    ?>
                                    <td class="py-2 pr-2 text-slate-200"><?= e($r['scheduled_at'] ?? '') ?></td>
                                    <td class="py-2 pr-2"><?= e($r['phone'] ?? '') ?></td>
                                    <td class="py-2 pr-2">
                                        <div class="text-[11px] text-slate-400 mb-1"><?= e($r['template'] ?? '') ?></div>
                                        <div class="text-xs text-slate-100 bg-slate-900/70 border border-slate-800 rounded-lg px-2 py-1.5 whitespace-pre-wrap break-words">
                                            <?= $msgText !== '' ? e($msgText) : '—' ?>
                                        </div>
                                        <?php if ($msgText !== ''): ?>
                                            <div class="mt-2">
                                                <button type="button"
                                                    class="js-copy-crm-message px-2 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 border border-slate-700 relative"
                                                    data-message="<?= e($msgText) ?>">
                                                    Скопировать сообщение
                                                    <span class="js-copy-crm-tooltip hidden absolute left-1/2 -translate-x-1/2 -top-8 px-2 py-1 rounded bg-emerald-600 text-white text-[10px] whitespace-nowrap">Скопировано!</span>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 pr-2 text-slate-200"><?= e($suggestedStr) ?></td>
                                    <td class="py-2 pr-2 text-slate-200">
                                        <?= $reason !== '' ? e($reason) : '—' ?>
                                    </td>
                                    <td class="py-2 pr-2"><?= e($statusUi) ?></td>
                                    <td class="py-2">
                                        <?php if (in_array($statusDb, ['pending', 'draft', 'ready_manual'], true)): ?>
                                            <?php if ($showOutboxQueueActions): ?>
                                                <div class="flex flex-col gap-2 items-start">
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                        <input type="hidden" name="action" value="send_manual">
                                                        <input type="hidden" name="id" value="<?= $outboxId ?>">
                                                        <button type="submit" class="text-[11px] text-emerald-400 hover:underline">Отметить отправленным</button>
                                                    </form>
                                                    <form method="post" class="inline">
                                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                        <input type="hidden" name="action" value="cancel">
                                                        <input type="hidden" name="id" value="<?= $outboxId ?>">
                                                        <button type="submit" class="text-[11px] text-red-400 hover:underline">Отменить</button>
                                                    </form>
                                                </div>
                                            <?php elseif ($statusDb === 'pending'): ?>
                                                <span class="text-[11px] text-slate-500">Тариф GROWTH</span>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
</main>
<script src="/assets/js/motion.js"></script>
<script>
(function () {
    function fallbackCopyText(text) {
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text || '';
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                ta.style.top = '-9999px';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                document.body.removeChild(ta);
                if (ok) resolve(true);
                else reject(new Error('copy_failed'));
            } catch (e) {
                reject(e);
            }
        });
    }

    function copyText(text) {
        if (navigator && navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text || '');
        }
        return fallbackCopyText(text);
    }

    document.querySelectorAll('.js-copy-crm-message').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var msg = this.getAttribute('data-message') || '';
            var tooltip = this.querySelector('.js-copy-crm-tooltip');
            copyText(msg).then(function () {
                if (!tooltip) return;
                tooltip.classList.remove('hidden');
                setTimeout(function () {
                    tooltip.classList.add('hidden');
                }, 1200);
            }).catch(function () {
                // Silent: copy failures shouldn't break CRM UI.
            });
        });
    });
})();
</script>
<?php if (is_demo_mode()): ?>
<script>(function(){var k='demo_pages_visited';var v=[];try{v=JSON.parse(sessionStorage.getItem(k)||'[]');}catch(e){}var p=location.pathname;if(v.indexOf(p)===-1){v.push(p);try{sessionStorage.setItem(k,JSON.stringify(v));}catch(e){}}})();</script>
<?php endif; ?>
</body>
</html>
