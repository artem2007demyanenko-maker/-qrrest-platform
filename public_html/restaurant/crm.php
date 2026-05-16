<?php

require_once __DIR__ . '/../../app/bootstrap.php';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
if (file_exists(__DIR__ . '/../../app/runtime_schema_bootstrap.php')) {
    require_once __DIR__ . '/../../app/runtime_schema_bootstrap.php';
}
if (file_exists(__DIR__ . '/../../app/billing.php')) {
    require_once __DIR__ . '/../../app/billing.php';
}
require_once __DIR__ . '/../../app/crm_repo.php';
if (file_exists(__DIR__ . '/../../app/crm_campaign_repo.php')) {
    require_once __DIR__ . '/../../app/crm_campaign_repo.php';
}
if (file_exists(__DIR__ . '/../../app/feedback_crm_bridge.php')) {
    require_once __DIR__ . '/../../app/feedback_crm_bridge.php';
}
if (file_exists(__DIR__ . '/../../app/retention_analytics.php')) {
    require_once __DIR__ . '/../../app/retention_analytics.php';
}
if (file_exists(__DIR__ . '/../../app/guest_retention.php')) {
    require_once __DIR__ . '/../../app/guest_retention.php';
}
if (file_exists(__DIR__ . '/../../app/guest_loyalty.php')) {
    require_once __DIR__ . '/../../app/guest_loyalty.php';
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
$authUser = auth_user();
if (function_exists('runtime_schema_ensure_crm_core')) {
    runtime_schema_ensure_crm_core(db());
}
$crmPaywallContext = function_exists('billing_get_feature_paywall_context')
    ? billing_get_feature_paywall_context((int)($authUser['id'] ?? 0), $restId, 'crm')
    : null;

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
$warnings = [];
$crmSchema = [
    'guests' => function_exists('db_table_exists') ? db_table_exists('guests') : true,
    'crm_campaigns' => function_exists('db_table_exists') ? db_table_exists('crm_campaigns') : true,
    'crm_visits' => function_exists('db_table_exists') ? db_table_exists('crm_visits') : true,
];
$crmSchemaReady = $crmSchema['guests'] && $crmSchema['crm_campaigns'] && $crmSchema['crm_visits'];
if (!$crmSchemaReady) {
    // Fallback: keep page readable and analytics blocks in empty-state mode.
    foreach ($crmSchema as $tableName => $exists) {
        if (!$exists) {
            $warnings[] = 'Нет части данных: таблица «' . $tableName . '» не найдена. CRM открыт в режиме просмотра с ограничениями — примените миграции.';
            error_log('CRM_SCHEMA_MISSING table=' . $tableName . ' restaurant_id=' . $restId);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_demo_mode()) {
    if (!$crmSchemaReady) {
        $errors[] = 'CRM работает в read-only режиме: часть таблиц схемы отсутствует. Примените миграции и повторите действие.';
    }
    $csrfOk = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals((string)$_SESSION['csrf'], (string)$_POST['csrf']);
    if ($errors === [] && !$csrfOk) {
        $errors[] = 'Неверный токен. Обновите страницу.';
    } elseif ($errors === []) {
        $action = trim($_POST['action'] ?? '');
        $allowWithoutFullCrm = ($action === 'save_manual_return')
            || $action === 'create_inactive_return_draft'
            || $action === 'bulk_inactive_return_drafts'
            || $action === 'create_loyalty_retention_draft'
            || $action === 'bulk_loyalty_retention_drafts'
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
        } elseif ($action === 'create_loyalty_retention_draft') {
            $guestId = (int)($_POST['guest_id'] ?? 0);
            $segmentType = trim((string)($_POST['segment_type'] ?? ''));
            $templateKey = trim((string)($_POST['template_key'] ?? ''));
            if ($guestId <= 0 || $segmentType === '') {
                $errors[] = 'Некорректный loyalty-сценарий.';
            } elseif (!function_exists('crm_create_loyalty_retention_outbox_draft')) {
                $errors[] = 'Функция недоступна.';
            } else {
                $res = crm_create_loyalty_retention_outbox_draft($restId, $segmentType, $guestId, $templateKey !== '' ? $templateKey : null);
                if (!empty($res['ok'])) {
                    $success = 'Retention-черновик создан из приоритетного сценария. Проверьте блок «Исходящие сообщения» (фильтр «Черновики»).';
                } elseif (($res['reason'] ?? '') === 'duplicate') {
                    $errors[] = 'Для этого гостя уже есть свежий retention draft/outbox. Откройте блок «Исходящие сообщения», чтобы продолжить работу без дубля.';
                } else {
                    $errors[] = 'Не удалось создать loyalty-черновик.';
                }
            }
        } elseif ($action === 'bulk_loyalty_retention_drafts') {
            $segmentType = trim((string)($_POST['segment_type'] ?? ''));
            $templateKey = trim((string)($_POST['template_key'] ?? ''));
            if ($segmentType === '') {
                $errors[] = 'Не выбран loyalty-сегмент.';
            } elseif (!function_exists('crm_bulk_create_loyalty_retention_outbox_drafts')) {
                $errors[] = 'Функция недоступна.';
            } else {
                $res = crm_bulk_create_loyalty_retention_outbox_drafts($restId, $segmentType, 50, $templateKey !== '' ? $templateKey : null);
                $success = 'Loyalty-черновиков создано: ' . (int)$res['created'] . '. Пропущено как дубли: ' . (int)$res['skipped_duplicate'] . '.';
                if ((int)$res['skipped_other'] > 0) {
                    $success .= ' Прочие пропуски: ' . (int)$res['skipped_other'] . '.';
                }
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
$outboxEmptyTitle = 'Нет сообщений';
$outboxEmptyText = 'Нет записей со статусом «' . $statusFilterUiLabel . '».';
if ($statusFilter === 'draft') {
    $outboxEmptyTitle = 'Черновиков пока нет';
    $outboxEmptyText = 'Создайте loyalty-сценарий или ручное сообщение выше — новые черновики появятся здесь.';
} elseif ($statusFilter === 'ready_manual') {
    $outboxEmptyTitle = 'Нет сообщений, готовых к ручной отправке';
    $outboxEmptyText = 'Когда вы подготовите сообщение и сохраните его как «готово к ручной отправке», оно появится здесь.';
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
$loyaltySegmentCatalog = function_exists('crm_loyalty_retention_segment_catalog')
    ? crm_loyalty_retention_segment_catalog()
    : [];
$loyaltyTemplateLibrary = function_exists('crm_loyalty_retention_template_library')
    ? crm_loyalty_retention_template_library()
    : [];
$loyaltyRetentionScenarios = [];
if (!is_demo_mode() && $loyaltySegmentCatalog !== [] && function_exists('crm_loyalty_retention_candidates')) {
    foreach ($loyaltySegmentCatalog as $segmentType => $cfg) {
        $candidates = crm_loyalty_retention_candidates($restId, $segmentType, 6);
        $loyaltyRetentionScenarios[$segmentType] = [
            'label' => (string)($cfg['label'] ?? $segmentType),
            'description' => (string)($cfg['description'] ?? ''),
            'goal' => (string)($cfg['goal'] ?? ''),
            'who' => (string)($cfg['who'] ?? ''),
            'offer_framing' => (string)($cfg['offer_framing'] ?? ''),
            'draft_title' => (string)($cfg['draft_title'] ?? ($cfg['label'] ?? $segmentType)),
            'recommended_template_key' => function_exists('crm_loyalty_retention_default_template_key_for_segment')
                ? crm_loyalty_retention_default_template_key_for_segment($segmentType)
                : '',
            'candidates' => $candidates,
            'count' => count(crm_loyalty_retention_candidates($restId, $segmentType, 200)),
        ];
    }
}
$loyaltyScenarioAnalytics = [];
if (!is_demo_mode() && function_exists('get_loyalty_retention_scenario_analytics_cached')) {
    try {
        $loyaltyScenarioAnalytics = get_loyalty_retention_scenario_analytics_cached($restId, 30);
    } catch (Throwable $e) {
        $loyaltyScenarioAnalytics = [];
    }
}
$loyaltyScenarioCount = count($loyaltySegmentCatalog);
$loyaltyScenarioCandidateTotal = 0;
foreach ($loyaltyRetentionScenarios as $_scenario) {
    $loyaltyScenarioCandidateTotal += (int)($_scenario['count'] ?? 0);
}
$loyaltyScenarioDraftsTotal = 0;
$loyaltyScenarioReturnedGuestsTotal = 0;
$loyaltyScenarioReturnedRevenueTotal = 0.0;
foreach ($loyaltyScenarioAnalytics as $_stat) {
    $loyaltyScenarioDraftsTotal += (int)($_stat['drafts_created'] ?? 0);
    $loyaltyScenarioReturnedGuestsTotal += (int)($_stat['returned_guests'] ?? 0);
    $loyaltyScenarioReturnedRevenueTotal += (float)($_stat['returned_revenue'] ?? 0);
}
$retentionPriorityQueue = function_exists('crm_loyalty_retention_priority_queue')
    ? crm_loyalty_retention_priority_queue($restId, 8)
    : [];
$manualReturnOutboxSummary = function_exists('crm_manual_return_outbox_summary')
    ? crm_manual_return_outbox_summary($restId, 30, 6)
    : ['total' => 0, 'draft' => 0, 'ready_manual' => 0, 'processed' => 0, 'canceled' => 0, 'failed' => 0, 'loyalty_rows' => 0, 'fallback_rows' => 0, 'recent' => []];
$crmSendBoardFetchRows = static function (string $status, int $limit) use ($restId): array {
    if (is_demo_mode()) {
        $demoRows = array_values(array_filter(demo_crm_outbox(), static function (array $row) use ($status): bool {
            return (string)($row['status'] ?? '') === $status;
        }));
        return array_slice($demoRows, 0, $limit);
    }
    if (!function_exists('crm_list_outbox')) {
        return [];
    }
    return crm_list_outbox($restId, $status, $limit);
};
$manualReturnStatusLabel = static function (string $status): string {
    return match ($status) {
        'draft' => 'Черновик',
        'ready_manual' => 'Готово к ручной отправке',
        'processed' => 'Отмечено как отправленное',
        'canceled' => 'Отменено',
        'failed' => 'Ошибка',
        default => $status !== '' ? $status : '—',
    };
};
$sendBoardReadyRows = array_merge(
    $crmSendBoardFetchRows('ready_manual', 10),
    $crmSendBoardFetchRows('pending', 10)
);
usort($sendBoardReadyRows, static function (array $a, array $b): int {
    return strcmp((string)($a['scheduled_at'] ?? ''), (string)($b['scheduled_at'] ?? ''));
});
$sendBoardDraftRows = $crmSendBoardFetchRows('draft', 10);
$sendBoardDoneRows = array_merge(
    $crmSendBoardFetchRows('processed', 10),
    $crmSendBoardFetchRows('failed', 6),
    $crmSendBoardFetchRows('canceled', 6)
);
usort($sendBoardDoneRows, static function (array $a, array $b): int {
    return strcmp((string)($b['scheduled_at'] ?? ''), (string)($a['scheduled_at'] ?? ''));
});
$sendBoardAllRows = array_merge($sendBoardReadyRows, $sendBoardDraftRows, $sendBoardDoneRows);
$sendBoardOutcomeMap = function_exists('get_manual_return_outbox_outcome_map')
    ? get_manual_return_outbox_outcome_map($restId, $sendBoardAllRows, 90)
    : [];
$crmOutboxBoardMeta = static function (array $row) use ($manualReturnStatusLabel, $sendBoardOutcomeMap): array {
    $payload = json_decode((string)($row['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $msgText = (string)($payload['text'] ?? '');
    $reason = (string)($payload['reason'] ?? ($payload['retention_reason'] ?? ($payload['cause'] ?? '')));
    $templateName = trim((string)($payload['template_name'] ?? ''));
    $segmentLabel = trim((string)($payload['segment_label'] ?? ($payload['draft_title'] ?? '')));
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
    $statusDb = (string)($row['status'] ?? '');
    $outcome = $sendBoardOutcomeMap[(int)($row['id'] ?? 0)] ?? ['returned' => false, 'return_order_id' => 0, 'return_order_total' => 0.0, 'return_order_created_at' => '', 'days_to_return' => null];
    return [
        'status_db' => $statusDb,
        'status_ui' => $manualReturnStatusLabel($statusDb),
        'message_text' => $msgText,
        'reason' => $reason,
        'template_name' => $templateName,
        'segment_label' => $segmentLabel,
        'suggested_str' => $suggestedNames !== [] ? implode(', ', array_slice($suggestedNames, 0, 4)) : '—',
        'returned' => !empty($outcome['returned']),
        'return_order_id' => (int)($outcome['return_order_id'] ?? 0),
        'return_order_total' => (float)($outcome['return_order_total'] ?? 0),
        'return_order_created_at' => (string)($outcome['return_order_created_at'] ?? ''),
        'days_to_return' => $outcome['days_to_return'] ?? null,
    ];
};
$crmCampaignsSummaryRows = function_exists('crm_campaign_list') ? crm_campaign_list($restId) : [];
$activeCampaignsCount = 0;
foreach ($crmCampaignsSummaryRows as $_campaign) {
    if ((int)($_campaign['active'] ?? 1) === 1) {
        $activeCampaignsCount++;
    }
}
$bestScenarioStats = [];
if ($loyaltyScenarioAnalytics !== []) {
    $bestScenarioStats = array_values($loyaltyScenarioAnalytics);
    usort($bestScenarioStats, static function (array $a, array $b): int {
        $revCmp = ((float)($b['returned_revenue'] ?? 0) <=> (float)($a['returned_revenue'] ?? 0));
        if ($revCmp !== 0) {
            return $revCmp;
        }
        $rateCmp = ((float)($b['return_rate'] ?? 0) <=> (float)($a['return_rate'] ?? 0));
        if ($rateCmp !== 0) {
            return $rateCmp;
        }
        return ((int)($b['returned_guests'] ?? 0) <=> (int)($a['returned_guests'] ?? 0));
    });
    $bestScenarioStats = array_slice($bestScenarioStats, 0, 3);
}
$comebackCandidates = [];
if ($crmSchema['guests'] && $crmSchema['crm_visits'] && file_exists(__DIR__ . '/../../app/guest_return_engine.php')) {
    // guest_return_engine depends on guest/visit history; fallback to empty list if schema is partial.
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
if ($crmSchema['guests'] && $crmSchema['crm_visits'] && function_exists('get_retention_stats')) {
    $retentionStats = get_retention_stats($restId);
}
if ($crmSchema['guests'] && $crmSchema['crm_visits'] && function_exists('get_guest_segments_summary')) {
    $guestSegments = get_guest_segments_summary($restId);
}

$guestSearchQ = trim((string)($_GET['gq'] ?? ''));
$guestSegFilter = trim((string)($_GET['gseg'] ?? 'all'));
if (!in_array($guestSegFilter, ['all', 'new', 'active', 'inactive'], true)) {
    $guestSegFilter = 'all';
}
$profileGuestId = (int)($_GET['profile'] ?? 0);
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
    // guests table can be missing on partially migrated environments; keep "no data" instead of throwing.
    $crmGuestsRowsRaw = ($crmSchema['guests'] && function_exists('crm_restaurant_guests_dashboard_rows'))
        ? crm_restaurant_guests_dashboard_rows($restId)
        : [];
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
        if (!$crmSchema['guests']) {
            $historyGuestRow = null;
            $historyOrders = [];
        } else {
        $historyGuestRow = crm_guest_lookup($restId, $historyGuestId);
        if ($historyGuestRow) {
            $historyOrders = crm_guest_orders_history($restId, $historyGuestId, 40);
        }
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
        $composeGuestRow = $crmSchema['guests'] ? crm_guest_lookup($restId, $composeGuestId) : null;
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

$crmQueryBase = static function (array $extra) use ($guestSearchQ, $guestSegFilter, $statusFilter, $profileGuestId, $historyGuestId, $composeGuestId): string {
    $q = array_merge([
        'status' => $statusFilter,
        'gq' => $guestSearchQ !== '' ? $guestSearchQ : null,
        'gseg' => $guestSegFilter !== 'all' ? $guestSegFilter : null,
        'profile' => $profileGuestId > 0 ? $profileGuestId : null,
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
$crmGuestProfile = null;
if ($profileGuestId > 0 && !is_demo_mode()) {
    $profileBase = crm_guest_lookup($restId, $profileGuestId);
    $profileMetrics = function_exists('crm_confirmed_guest_metrics_row') ? crm_confirmed_guest_metrics_row($restId, $profileGuestId) : null;
    if ($profileBase || $profileMetrics) {
        $crmGuestProfile = array_merge(is_array($profileBase) ? $profileBase : [], is_array($profileMetrics) ? $profileMetrics : []);
        $crmGuestProfile['crm_guest_id'] = $profileGuestId;
        $crmGuestProfile['orders'] = function_exists('crm_guest_orders_history') ? crm_guest_orders_history($restId, $profileGuestId, 5) : [];
        $crmGuestProfile['retention_rows'] = function_exists('crm_guest_manual_return_history') ? crm_guest_manual_return_history($restId, $profileGuestId, 5) : [];
        $crmGuestProfile['loyalty_balance'] = 0;
        $crmGuestProfile['guest_name'] = trim((string)($crmGuestProfile['guest_display_name'] ?? ''));
        $crmGuestProfile['card_token'] = '';
        $crmGuestProfile['current_scenario'] = null;

        $loyaltyGuestId = (int)($crmGuestProfile['loyalty_guest_id'] ?? 0);
        if ($loyaltyGuestId > 0 && function_exists('db')) {
            try {
                $pdo = db();
                if (function_exists('guest_loyalty_balance_by_guest_rest')) {
                    $crmGuestProfile['loyalty_balance'] = guest_loyalty_balance_by_guest_rest($pdo, $restId, $loyaltyGuestId);
                }
                if (function_exists('guest_get_card_by_guest_rest')) {
                    $cardRow = guest_get_card_by_guest_rest($pdo, $restId, $loyaltyGuestId);
                    if ($cardRow) {
                        if ($crmGuestProfile['guest_name'] === '') {
                            $crmGuestProfile['guest_name'] = trim((string)($cardRow['name'] ?? ''));
                        }
                        $uidCol = array_key_exists('public_uid', $cardRow) ? 'public_uid' : 'card_uid';
                        if (function_exists('guest_card_make_token')) {
                            $crmGuestProfile['card_token'] = guest_card_make_token((string)($cardRow[$uidCol] ?? ''));
                        }
                    }
                }
            } catch (Throwable $e) {
                // keep profile best-effort
            }
        }

        if (function_exists('crm_loyalty_retention_segment_catalog') && function_exists('crm_loyalty_retention_candidate_by_guest')) {
            $bestScenario = null;
            foreach (array_keys(crm_loyalty_retention_segment_catalog()) as $segmentType) {
                $candidate = crm_loyalty_retention_candidate_by_guest($restId, $segmentType, $profileGuestId);
                if (!$candidate) {
                    continue;
                }
                if (function_exists('crm_loyalty_retention_priority_score')) {
                    $candidate['priority_score'] = crm_loyalty_retention_priority_score($candidate);
                }
                if ($bestScenario === null || (int)($candidate['priority_score'] ?? 0) > (int)($bestScenario['priority_score'] ?? 0)) {
                    $bestScenario = $candidate;
                }
            }
            $crmGuestProfile['current_scenario'] = $bestScenario;
        }
        $crmGuestProfile['blocking_outbox'] = function_exists('crm_outbox_recent_blocking_manual_return_state')
            ? crm_outbox_recent_blocking_manual_return_state($restId, $profileGuestId, 7)
            : null;
        $crmGuestProfile['retention_outcomes'] = function_exists('get_manual_return_outbox_outcome_map')
            ? get_manual_return_outbox_outcome_map($restId, $crmGuestProfile['retention_rows'], 90)
            : [];
        $crmGuestProfile['can_create_scenario_draft'] = false;
        $crmGuestProfile['scenario_has_same_draft'] = false;
        if (!empty($crmGuestProfile['current_scenario']) && is_array($crmGuestProfile['current_scenario'])) {
            $currentScenarioType = (string)($crmGuestProfile['current_scenario']['segment_type'] ?? '');
            $blockingOutbox = is_array($crmGuestProfile['blocking_outbox'] ?? null) ? $crmGuestProfile['blocking_outbox'] : null;
            $crmGuestProfile['scenario_has_same_draft'] = $blockingOutbox !== null
                && (string)($blockingOutbox['segment_type'] ?? '') === $currentScenarioType;
            $crmGuestProfile['can_create_scenario_draft'] = $blockingOutbox === null;
        }
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

<?php
$restaurantSidebarActive = 'crm';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? '');
require __DIR__ . '/_sidebar_mobile.php';
require __DIR__ . '/_sidebar.php';
?>

<main class="flex-1 min-w-0 p-4 md:p-6 overflow-x-hidden">
    <div class="max-w-6xl mx-auto space-y-6 page-enter">
        <?php
        $businessNavActive = 'crm';
        require __DIR__ . '/_restaurant_cabinet_context.php';
        require __DIR__ . '/_restaurant_business_nav.php';
        ?>
        <?php if ($success): ?>
            <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/40 px-4 py-3.5 text-sm text-emerald-100" role="status"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="rounded-xl bg-red-500/10 border border-red-500/40 px-4 py-3.5 text-sm text-red-100 space-y-1" role="alert">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($warnings): ?>
            <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-3.5 text-sm text-amber-100 space-y-1" role="status">
                <?php foreach ($warnings as $warning): ?><div><?= e($warning) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (is_demo_mode()): ?>
        <div class="rounded-xl bg-amber-500/10 border border-amber-500/40 px-4 py-2.5 flex items-center justify-center gap-2 text-sm text-amber-200">
            <span aria-hidden="true">⚠</span>
            <span>Демо-режим: действия только имитируются.</span>
        </div>
        <?php endif; ?>
        <?php if ($trialRequiresUpgrade || !$crmEnabled): ?>
        <?php
            $ctx = is_array($crmPaywallContext) ? $crmPaywallContext : [];
            $crmPaywallTone = (string)($ctx['tone'] ?? ($trialRequiresUpgrade ? 'rose' : 'amber'));
            $crmPaywallClasses = [
                'sky' => 'border-sky-500/40 bg-sky-500/10 text-sky-100',
                'amber' => 'border-amber-500/40 bg-amber-500/10 text-amber-100',
                'rose' => 'border-rose-500/40 bg-rose-500/10 text-rose-100',
                'emerald' => 'border-emerald-500/40 bg-emerald-500/10 text-emerald-100',
            ];
            $crmPaywallClass = $crmPaywallClasses[$crmPaywallTone] ?? $crmPaywallClasses['amber'];
        ?>
        <div class="rounded-2xl border px-4 py-4 <?= e($crmPaywallClass) ?>" role="status">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-3xl">
                    <div class="inline-flex items-center rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide">
                        <?= e((string)($ctx['phase_label'] ?? 'Следующий шаг')) ?>
                    </div>
                    <p class="text-slate-100 font-medium mt-3"><?= e((string)($ctx['title'] ?? 'CRM возврата гостей')) ?></p>
                    <p class="text-xs mt-1 opacity-90"><?= e((string)($ctx['subtitle'] ?? 'Подключите CRM, чтобы возвращать гостей системно.')) ?></p>
                    <p class="text-xs mt-3 opacity-80"><?= e((string)($ctx['why_now'] ?? '')) ?></p>
                </div>
                <a href="<?= e((string)($ctx['cta_url'] ?? '/restaurant/activate.php?plan=growth')) ?>" class="inline-flex items-center px-4 py-2 rounded-xl bg-white/10 hover:bg-white/15 text-white text-sm font-medium border border-white/10">
                    <?= e((string)($ctx['cta_label'] ?? 'Открыть тариф GROWTH')) ?>
                </a>
            </div>
            <div class="grid gap-3 lg:grid-cols-2 mt-4">
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide opacity-70">Что откроется после активации</div>
                    <ul class="mt-2 space-y-2 text-xs opacity-90">
                        <?php foreach (array_slice((array)($ctx['benefits'] ?? []), 0, 3) as $benefit): ?>
                            <li class="flex items-start gap-2"><span class="mt-1">•</span><span><?= e((string)$benefit) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div>
                    <div class="text-[11px] font-semibold uppercase tracking-wide opacity-70">Что уже настроено и сохранится</div>
                    <ul class="mt-2 space-y-2 text-xs opacity-90">
                        <?php foreach (array_slice((array)($ctx['proof_items'] ?? []), 0, 3) as $proof): ?>
                            <li class="flex items-start gap-2"><span class="mt-1">•</span><span><?= e((string)$proof) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-3 text-[11px] opacity-70"><?= e((string)($ctx['preservation_text'] ?? '')) ?></p>
                </div>
            </div>
        </div>
        <?php elseif ($trialInfo['is_trial'] && !$trialInfo['is_expired']): ?>
        <div class="rounded-2xl bg-sky-500/10 border border-sky-500/50 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
            <span class="text-sm text-sky-100">
                <?php if ((int)$trialInfo['days_left'] <= 3): ?>
                    Пробный период скоро закончится: осталось <?= (int)$trialInfo['days_left'] ?> дн.
                <?php else: ?>
                    Пробный период: осталось <?= (int)$trialInfo['days_left'] ?> дн.
                <?php endif; ?>
            </span>
            <a href="/restaurant/activate.php?plan=growth" class="px-3 py-1.5 rounded-xl bg-sky-500/30 hover:bg-sky-500/50 text-sky-100 text-sm font-medium">Сохранить доступ к CRM</a>
        </div>
        <?php endif; ?>

        <?php if (!$trialRequiresUpgrade): ?>

        <section id="guests-return" class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 md:p-8 space-y-6 shadow-xl shadow-black/10">
            <header>
                <h1 class="text-2xl md:text-3xl font-bold text-white tracking-tight">CRM возврата гостей</h1>
                <p class="text-slate-400 text-sm md:text-base mt-1"><?= e($currentRestaurant['name'] ?? '') ?></p>
                <p class="text-xs text-slate-500 mt-2 max-w-2xl">Это единый экран loyalty-driven CRM: здесь ресторан видит готовые сценарии возврата, создаёт manual drafts и оценивает, какие сценарии реально приводят к новым paid visits. Авто-отправки нет.</p>
                <p class="text-xs text-slate-600 mt-2">Порог «давно не был» для сегментов: <span class="text-slate-300 font-medium"><?= (int)$inactiveThresholdDays ?> дн.</span>
                    — <a href="/restaurant/settings.php#crm-return-settings" class="text-indigo-400 hover:text-indigo-300">изменить в настройках CRM</a></p>
            </header>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Готовые сценарии</div>
                    <div class="text-2xl font-bold text-white mt-1"><?= $loyaltyScenarioCount ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Готовые loyalty retention-сценарии</div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Гости к возврату</div>
                    <div class="text-2xl font-bold text-emerald-300 mt-1"><?= $loyaltyScenarioCandidateTotal ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Суммарно по loyalty-сценариям</div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Черновики за 30 дней</div>
                    <div class="text-2xl font-bold text-cyan-300 mt-1"><?= $loyaltyScenarioDraftsTotal ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Создано из loyalty-сценариев</div>
                </div>
                <div class="rounded-xl bg-[#111827] border border-slate-700/80 p-4">
                    <div class="text-[11px] text-slate-500 uppercase tracking-wide">Возвраты по сценариям</div>
                    <div class="text-2xl font-bold text-white mt-1"><?= $loyaltyScenarioReturnedGuestsTotal ?></div>
                    <div class="text-[11px] text-slate-500 mt-1"><?= e(number_format($loyaltyScenarioReturnedRevenueTotal, 0, '.', ' ')) ?> ₽ выручки</div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-800 bg-slate-950/40 p-4">
                <div class="text-xs uppercase tracking-wide text-slate-500 mb-3">Как работать с экраном</div>
                <div class="flex flex-wrap gap-2">
                    <a href="#loyalty-scenarios" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 font-medium">1. Сценарии и кандидаты</a>
                    <a href="#crm-outbox" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 font-medium">2. Черновики и исходящие</a>
                    <a href="#loyalty-analytics" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 font-medium">3. Что реально работает</a>
                    <a href="#crm-secondary-tools" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 font-medium">4. Дополнительные инструменты</a>
                </div>
            </div>

            <?php if (is_array($crmGuestProfile)): ?>
            <section id="crm-guest-profile" class="rounded-2xl border border-sky-500/20 bg-gradient-to-br from-sky-500/5 to-slate-900/60 p-5 md:p-6 space-y-4">
                <?php
                $profileBlockingOutbox = is_array($crmGuestProfile['blocking_outbox'] ?? null) ? $crmGuestProfile['blocking_outbox'] : null;
                $profileBlockingStatus = $profileBlockingOutbox ? $manualReturnStatusLabel((string)($profileBlockingOutbox['status'] ?? '')) : '';
                $profileBlockingSegment = trim((string)($profileBlockingOutbox['segment_label'] ?? $profileBlockingOutbox['segment_type'] ?? ''));
                $profileCurrentScenario = (!empty($crmGuestProfile['current_scenario']) && is_array($crmGuestProfile['current_scenario'])) ? $crmGuestProfile['current_scenario'] : null;
                $profileCurrentScenarioType = (string)($profileCurrentScenario['segment_type'] ?? '');
                $profileCurrentTemplateKey = (string)($profileCurrentScenario['recommended_template_key'] ?? '');
                $profileCurrentTemplateName = (string)($profileCurrentScenario['recommended_template_name'] ?? '');
                $profileScenarioHasSameDraft = !empty($crmGuestProfile['scenario_has_same_draft']);
                $profileCanCreateScenarioDraft = !empty($crmGuestProfile['can_create_scenario_draft']);
                $profileOutboxLink = $crmQueryBase(['status' => 'draft', 'compose' => null, 'history' => null]) . '#crm-outbox';
                ?>
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-sky-300/80">CRM guest mini-profile</div>
                        <h2 class="text-lg font-bold text-white mt-1">
                            <?= e(($crmGuestProfile['guest_name'] ?? '') !== '' ? (string)$crmGuestProfile['guest_name'] : ((string)($crmGuestProfile['phone'] ?? '') !== '' ? (string)$crmGuestProfile['phone'] : ('CRM guest #' . (int)$crmGuestProfile['crm_guest_id']))) ?>
                        </h2>
                        <div class="text-xs text-slate-500 mt-1">
                            CRM guest #<?= (int)$crmGuestProfile['crm_guest_id'] ?>
                            <?php if (!empty($crmGuestProfile['phone'])): ?> · <?= e((string)$crmGuestProfile['phone']) ?><?php endif; ?>
                            <?php if (!empty($crmGuestProfile['loyalty_guest_id'])): ?> · loyalty guest #<?= (int)$crmGuestProfile['loyalty_guest_id'] ?><?php endif; ?>
                        </div>
                        <?php if ($profileBlockingOutbox): ?>
                            <div class="mt-3 inline-flex items-center rounded-xl border border-violet-500/20 bg-violet-500/10 px-3 py-2 text-[11px] text-violet-100">
                                В работе: <?= e($profileBlockingStatus) ?><?php if ($profileBlockingSegment !== ''): ?> · <?= e($profileBlockingSegment) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($profileCurrentScenario && $profileCanCreateScenarioDraft): ?>
                            <form method="post" class="inline-flex">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="create_loyalty_retention_draft">
                                <input type="hidden" name="guest_id" value="<?= (int)$crmGuestProfile['crm_guest_id'] ?>">
                                <input type="hidden" name="segment_type" value="<?= e($profileCurrentScenarioType) ?>">
                                <input type="hidden" name="template_key" value="<?= e($profileCurrentTemplateKey) ?>">
                                <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">Создать черновик по сценарию</button>
                            </form>
                        <?php elseif ($profileCurrentScenario && $profileScenarioHasSameDraft): ?>
                            <a href="<?= e($profileOutboxLink) ?>" class="px-3 py-2 rounded-xl bg-violet-500/15 border border-violet-500/30 text-violet-200 text-xs font-semibold">Черновик уже есть</a>
                        <?php elseif ($profileBlockingOutbox): ?>
                            <a href="<?= e($profileOutboxLink) ?>" class="px-3 py-2 rounded-xl bg-amber-500/15 border border-amber-500/30 text-amber-200 text-xs font-semibold">Гость уже в работе</a>
                        <?php endif; ?>
                        <a href="<?= e($crmQueryBase(['compose' => (int)$crmGuestProfile['crm_guest_id'], 'history' => null])) ?>#crm-compose" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold">Открыть ручное сообщение</a>
                        <a href="<?= e($crmQueryBase(['history' => (int)$crmGuestProfile['crm_guest_id'], 'compose' => null])) ?>#guests-base" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium">Смотреть заказы гостя</a>
                        <a href="#crm-guest-profile-touches" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium">Смотреть retention touches</a>
                    </div>
                </div>

                <div class="grid grid-cols-2 xl:grid-cols-5 gap-3">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                        <div class="text-[11px] uppercase tracking-wide text-slate-500">Бонусный баланс</div>
                        <div class="text-2xl font-bold text-emerald-300 mt-1"><?= (int)($crmGuestProfile['loyalty_balance'] ?? 0) ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                        <div class="text-[11px] uppercase tracking-wide text-slate-500">Paid visits</div>
                        <div class="text-2xl font-bold text-white mt-1"><?= (int)($crmGuestProfile['visits_count'] ?? 0) ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                        <div class="text-[11px] uppercase tracking-wide text-slate-500">Последний paid visit</div>
                        <div class="text-sm font-semibold text-slate-100 mt-2"><?= !empty($crmGuestProfile['last_seen_at']) ? e((string)$crmGuestProfile['last_seen_at']) : '—' ?></div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                        <div class="text-[11px] uppercase tracking-wide text-slate-500">Заказы / выручка</div>
                        <div class="text-sm font-semibold text-slate-100 mt-2"><?= (int)($crmGuestProfile['order_count'] ?? 0) ?> · <?= e(number_format((float)($crmGuestProfile['total_spent'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                        <div class="text-[11px] uppercase tracking-wide text-slate-500">Карта ресторана</div>
                        <div class="text-sm font-semibold text-slate-100 mt-2"><?= !empty($crmGuestProfile['card_token']) ? 'Есть карта' : 'Нет карты' ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 space-y-3">
                        <div class="text-sm font-semibold text-slate-100">Актуальный сценарий возврата</div>
                        <?php if ($profileCurrentScenario): ?>
                            <?php $profileScenario = $profileCurrentScenario; ?>
                            <div class="text-sm font-medium text-emerald-300"><?= e((string)($profileScenario['segment_label'] ?? $profileScenario['segment_type'] ?? 'Сценарий')) ?></div>
                            <div class="text-xs text-slate-400"><?= e((string)($profileScenario['reason_text'] ?? '')) ?></div>
                            <div class="text-[11px] text-slate-500">Шаблон по умолчанию: <?= e((string)($profileScenario['recommended_template_name'] ?? '—')) ?></div>
                            <div class="text-xs text-slate-300 bg-slate-900/70 border border-slate-800 rounded-lg px-3 py-2"><?= e((string)($profileScenario['message_text'] ?? '')) ?></div>
                            <div class="flex flex-wrap gap-2 pt-1">
                                <?php if ($profileCanCreateScenarioDraft): ?>
                                    <form method="post" class="inline-flex">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="create_loyalty_retention_draft">
                                        <input type="hidden" name="guest_id" value="<?= (int)$crmGuestProfile['crm_guest_id'] ?>">
                                        <input type="hidden" name="segment_type" value="<?= e($profileCurrentScenarioType) ?>">
                                        <input type="hidden" name="template_key" value="<?= e($profileCurrentTemplateKey) ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-semibold">Создать черновик</button>
                                    </form>
                                <?php elseif ($profileScenarioHasSameDraft): ?>
                                    <a href="<?= e($profileOutboxLink) ?>" class="px-3 py-1.5 rounded-lg bg-violet-500/15 border border-violet-500/30 text-violet-200 text-[11px] font-semibold">Черновик уже есть</a>
                                <?php elseif ($profileBlockingOutbox): ?>
                                    <a href="<?= e($profileOutboxLink) ?>" class="px-3 py-1.5 rounded-lg bg-amber-500/15 border border-amber-500/30 text-amber-200 text-[11px] font-semibold">Гость уже в работе</a>
                                <?php endif; ?>
                                <a href="<?= e($crmQueryBase(['compose' => (int)$crmGuestProfile['crm_guest_id'], 'history' => null])) ?>#crm-compose" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-medium">Открыть ручное сообщение</a>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-slate-400">Сейчас для этого гостя нет явного loyalty-сценария в очереди.</div>
                        <?php endif; ?>
                    </div>

                    <div id="crm-guest-profile-orders" class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 space-y-3">
                        <div class="text-sm font-semibold text-slate-100">Последние заказы</div>
                        <?php if (($crmGuestProfile['orders'] ?? []) === []): ?>
                            <div class="text-sm text-slate-400">Подтверждённых заказов пока нет.</div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach (array_slice((array)$crmGuestProfile['orders'], 0, 5) as $orderRow): ?>
                                    <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2 text-[11px] text-slate-300">
                                        <div class="font-medium text-slate-100">Заказ #<?= (int)($orderRow['id'] ?? 0) ?> · <?= e(number_format((float)($orderRow['order_total'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                                        <div class="text-slate-500 mt-1"><?= e((string)($orderRow['created_at'] ?? '')) ?> · <?= e((string)($orderRow['payment_status'] ?? '')) ?> / <?= e((string)($orderRow['order_status'] ?? '')) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div id="crm-guest-profile-touches" class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 space-y-3">
                        <div class="text-sm font-semibold text-slate-100">Последние retention touches</div>
                        <?php if (($crmGuestProfile['retention_rows'] ?? []) === []): ?>
                            <div class="text-sm text-slate-400">Для этого гостя ещё не было manual retention-запусков.</div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ((array)$crmGuestProfile['retention_rows'] as $retRow): ?>
                                    <?php
                                    $retPayload = json_decode((string)($retRow['payload_json'] ?? '{}'), true);
                                    if (!is_array($retPayload)) {
                                        $retPayload = [];
                                    }
                                    $retOutcome = $crmGuestProfile['retention_outcomes'][(int)($retRow['id'] ?? 0)] ?? ['returned' => false];
                                    ?>
                                    <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2 text-[11px] text-slate-300">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="font-medium text-slate-100"><?= e((string)($retPayload['segment_label'] ?? $retPayload['draft_title'] ?? 'Manual return')) ?></div>
                                            <span class="text-slate-500"><?= e($manualReturnStatusLabel((string)($retRow['status'] ?? ''))) ?></span>
                                        </div>
                                        <div class="text-slate-500 mt-1"><?= e((string)($retRow['created_at'] ?? '')) ?><?php if (!empty($retPayload['template_name'])): ?> · шаблон <?= e((string)$retPayload['template_name']) ?><?php endif; ?></div>
                                        <?php if (!empty($retOutcome['returned'])): ?>
                                            <div class="text-emerald-200 mt-1">Вернулся: заказ #<?= (int)($retOutcome['return_order_id'] ?? 0) ?> · <?= e(number_format((float)($retOutcome['return_order_total'] ?? 0), 0, '.', ' ')) ?> ₽</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <section class="rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/5 to-slate-900/50 p-5 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-emerald-300/80">Кого возвращать в первую очередь</div>
                            <h2 class="text-base font-bold text-white mt-1">Приоритетная очередь гостей</h2>
                        </div>
                        <a href="#loyalty-scenarios" class="text-[11px] text-emerald-300 hover:text-emerald-200 font-medium">Открыть сценарии</a>
                    </div>
                    <p class="text-xs text-slate-500">Очередь собирается из loyalty-сценариев по paid visits, бонусному балансу и ценности гостя. Это быстрый ответ, кому стоит написать в первую очередь.</p>
                    <?php if ($retentionPriorityQueue === []): ?>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-6 text-center">
                            <div class="text-sm font-medium text-slate-200">Сейчас нет явных кандидатов на возврат</div>
                            <div class="text-xs text-slate-500 mt-2">Когда появятся loyalty-сегменты с подтверждёнными paid visits и балансом, здесь сформируется приоритетная очередь.</div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($retentionPriorityQueue as $candidate): ?>
                                <?php
                                $candidateGuestId = (int)($candidate['crm_guest_id'] ?? 0);
                                $candidatePhone = trim((string)($candidate['phone'] ?? ''));
                                $candidateLabel = trim((string)($candidate['segment_label'] ?? $candidate['label'] ?? $candidate['segment_type'] ?? 'Сценарий'));
                                $candidateReason = trim((string)($candidate['reason_text'] ?? $candidate['description'] ?? ''));
                                $candidateBalance = (int)($candidate['loyalty_balance'] ?? 0);
                                $candidateDays = (int)($candidate['days_since_paid_visit'] ?? 0);
                                $candidateVisits = (int)($candidate['visits_count'] ?? 0);
                                $candidateAvgCheck = (float)($candidate['avg_check'] ?? 0);
                                $candidateScore = (int)($candidate['priority_score'] ?? 0);
                                $candidateTemplateKey = trim((string)($candidate['recommended_template_key'] ?? ''));
                                $candidateTemplateName = trim((string)($candidate['recommended_template_name'] ?? ''));
                                $candidateHasSameDraft = !empty($candidate['has_existing_draft_for_same_segment']);
                                $candidateHasBlockingDraft = !empty($candidate['has_any_existing_blocking_draft']);
                                $candidateOutboxId = (int)($candidate['existing_outbox_id'] ?? 0);
                                $candidateOutboxStatus = $manualReturnStatusLabel((string)($candidate['existing_outbox_status'] ?? ''));
                                $candidateOutboxSegment = trim((string)($candidate['existing_outbox_segment_label'] ?? $candidate['existing_outbox_segment_type'] ?? ''));
                                $candidateOutboxTemplate = trim((string)($candidate['existing_outbox_template_name'] ?? ''));
                                $candidateCanCreate = !empty($candidate['can_one_click_create']);
                                $candidateFeedbackHasDraft = !empty($candidate['feedback_has_same_segment_draft']);
                                $candidateFeedbackReturned = !empty($candidate['feedback_returned']);
                                $candidateFeedbackStatus = $manualReturnStatusLabel((string)($candidate['feedback_outbox_status'] ?? ''));
                                $candidateFeedbackDraftAt = trim((string)($candidate['feedback_outbox_created_at'] ?? ''));
                                $candidateFeedbackTemplate = trim((string)($candidate['feedback_template_name'] ?? ''));
                                $candidateFeedbackOrderId = (int)($candidate['feedback_return_order_id'] ?? 0);
                                $candidateFeedbackOrderAt = trim((string)($candidate['feedback_return_order_created_at'] ?? ''));
                                $candidateFeedbackOrderTotal = (float)($candidate['feedback_return_order_total'] ?? 0);
                                $candidateFeedbackDays = $candidate['feedback_days_to_return'] ?? null;
                                $queueOutboxLink = $crmQueryBase(['status' => 'draft']) . '#crm-outbox';
                                ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-100"><?= e($candidatePhone !== '' ? $candidatePhone : ('CRM guest #' . $candidateGuestId)) ?></div>
                                            <div class="text-[11px] text-emerald-300 mt-1"><?= e($candidateLabel) ?></div>
                                        </div>
                                        <span class="shrink-0 inline-flex items-center rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2 py-1 text-[11px] font-semibold text-emerald-200">Приоритет <?= $candidateScore ?></span>
                                    </div>
                                    <?php if ($candidateReason !== ''): ?>
                                        <div class="text-xs text-slate-400 mt-2"><?= e($candidateReason) ?></div>
                                    <?php endif; ?>
                                    <div class="flex flex-wrap gap-2 mt-3 text-[11px]">
                                        <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-300"><?= $candidateBalance ?> бонусов</span>
                                        <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-300"><?= $candidateDays ?> дн. без paid visit</span>
                                        <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-300"><?= $candidateVisits ?> paid visits</span>
                                        <?php if ($candidateAvgCheck > 0): ?>
                                            <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-300">ср. чек <?= e(number_format($candidateAvgCheck, 0, '.', ' ')) ?> ₽</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2 mt-3 text-[11px] text-slate-300">
                                        <span class="text-slate-500">One-click сценарий:</span>
                                        <span class="ml-1"><?= e($candidateLabel) ?></span>
                                        <?php if ($candidateTemplateName !== ''): ?>
                                            <span class="text-slate-500">· шаблон</span>
                                            <span class="ml-1"><?= e($candidateTemplateName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($candidateFeedbackHasDraft): ?>
                                        <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2 mt-3 text-[11px] text-slate-300 space-y-2">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="inline-flex items-center rounded-lg border border-cyan-500/20 bg-cyan-500/10 px-2 py-1 text-[10px] font-semibold text-cyan-200">Черновик создан</span>
                                                <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-800 px-2 py-1 text-[10px] text-slate-300"><?= e($candidateFeedbackStatus) ?></span>
                                                <?php if ($candidateFeedbackReturned): ?>
                                                    <span class="inline-flex items-center rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2 py-1 text-[10px] font-semibold text-emerald-200">Гость вернулся</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-slate-400">
                                                Сценарий уже запускался<?= $candidateFeedbackDraftAt !== '' ? ' · ' . e($candidateFeedbackDraftAt) : '' ?>
                                                <?php if ($candidateFeedbackTemplate !== ''): ?> · шаблон <?= e($candidateFeedbackTemplate) ?><?php endif; ?>
                                            </div>
                                            <?php if ($candidateFeedbackReturned): ?>
                                                <div class="text-emerald-200">
                                                    После retention touch был confirmed paid order
                                                    <?php if ($candidateFeedbackOrderId > 0): ?> #<?= $candidateFeedbackOrderId ?><?php endif; ?>
                                                    <?php if ($candidateFeedbackOrderTotal > 0): ?> · <?= e(number_format($candidateFeedbackOrderTotal, 0, '.', ' ')) ?> ₽<?php endif; ?>
                                                    <?php if ($candidateFeedbackOrderAt !== ''): ?> · <?= e($candidateFeedbackOrderAt) ?><?php endif; ?>
                                                    <?php if ($candidateFeedbackDays !== null): ?> · вернулся через <?= e(number_format((float)$candidateFeedbackDays, 0, '.', ' ')) ?> дн.<?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-slate-500">После этого сценария нового confirmed paid order пока не было.</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex flex-wrap gap-2 mt-3">
                                        <?php if ($candidateCanCreate): ?>
                                            <form method="post" class="inline-flex">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="action" value="create_loyalty_retention_draft">
                                                <input type="hidden" name="guest_id" value="<?= $candidateGuestId ?>">
                                                <input type="hidden" name="segment_type" value="<?= e((string)($candidate['segment_type'] ?? '')) ?>">
                                                <input type="hidden" name="template_key" value="<?= e($candidateTemplateKey) ?>">
                                                <button type="submit" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-xs text-white font-semibold">Создать черновик</button>
                                            </form>
                                        <?php elseif ($candidateHasSameDraft): ?>
                                            <a href="<?= e($queueOutboxLink) ?>" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-violet-500/15 border border-violet-500/30 text-xs text-violet-200 font-semibold">Черновик уже есть</a>
                                        <?php elseif ($candidateHasBlockingDraft): ?>
                                            <a href="<?= e($queueOutboxLink) ?>" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-amber-500/15 border border-amber-500/30 text-xs text-amber-200 font-semibold">У гостя уже есть другой draft</a>
                                        <?php endif; ?>
                                        <a href="<?= e($crmQueryBase(['profile' => $candidateGuestId])) ?>#crm-guest-profile" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-sky-600/80 hover:bg-sky-500 text-xs text-white font-semibold">Профиль гостя</a>
                                        <a href="<?= e($crmQueryBase(['history' => $candidateGuestId])) ?>#guests-base" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 font-medium">История гостя</a>
                                        <a href="<?= e($crmQueryBase(['compose' => $candidateGuestId])) ?>#crm-compose" class="inline-flex items-center px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 font-medium">Открыть ручное сообщение</a>
                                    </div>
                                    <?php if ($candidateHasBlockingDraft): ?>
                                        <div class="text-[11px] text-slate-500 mt-3">
                                            Уже есть retention-запуск:
                                            <span class="text-slate-300"><?= e($candidateOutboxStatus) ?></span>
                                            <?php if ($candidateOutboxSegment !== ''): ?> · <?= e($candidateOutboxSegment) ?><?php endif; ?>
                                            <?php if ($candidateOutboxTemplate !== ''): ?> · шаблон <?= e($candidateOutboxTemplate) ?><?php endif; ?>
                                            <?php if ($candidateOutboxId > 0): ?> · запись #<?= $candidateOutboxId ?><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="rounded-2xl border border-cyan-500/20 bg-gradient-to-br from-cyan-500/5 to-slate-900/50 p-5 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-cyan-300/80">Что уже запущено сейчас</div>
                            <h2 class="text-base font-bold text-white mt-1">Черновики, ручная отправка и кампании</h2>
                        </div>
                        <a href="<?= e($crmQueryBase(['status' => 'draft'])) ?>#crm-outbox" class="text-[11px] text-cyan-300 hover:text-cyan-200 font-medium">Открыть outbox</a>
                    </div>
                    <p class="text-xs text-slate-500">Этот блок замыкает ежедневную работу: видно, сколько loyalty-черновиков уже подготовлено, что ждёт ручной отправки и есть ли активные CRM-кампании.</p>
                    <div class="grid grid-cols-2 gap-2 text-[11px]">
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-3">
                            <div class="text-slate-500 uppercase tracking-wide">Черновики</div>
                            <div class="text-xl font-bold text-cyan-300 mt-1"><?= (int)($manualReturnOutboxSummary['draft'] ?? 0) ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-3">
                            <div class="text-slate-500 uppercase tracking-wide">Готово к отправке</div>
                            <div class="text-xl font-bold text-white mt-1"><?= (int)($manualReturnOutboxSummary['ready_manual'] ?? 0) ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-3">
                            <div class="text-slate-500 uppercase tracking-wide">Loyalty-drafts</div>
                            <div class="text-xl font-bold text-emerald-300 mt-1"><?= (int)($manualReturnOutboxSummary['loyalty_rows'] ?? 0) ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-3 py-3">
                            <div class="text-slate-500 uppercase tracking-wide">Активные кампании</div>
                            <div class="text-xl font-bold text-violet-300 mt-1"><?= $activeCampaignsCount ?></div>
                        </div>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3 space-y-2">
                        <div class="text-xs font-semibold text-slate-200">Последние retention-запуски</div>
                        <?php if (($manualReturnOutboxSummary['recent'] ?? []) === []): ?>
                            <div class="text-xs text-slate-500">За последние 30 дней ещё не было retention-черновиков. Начните со сценариев ниже — новые записи появятся здесь автоматически.</div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($manualReturnOutboxSummary['recent'] as $outboxRow): ?>
                                    <?php
                                    $outboxPhone = trim((string)($outboxRow['phone'] ?? ''));
                                    $outboxSegment = trim((string)($outboxRow['segment_label'] ?? $outboxRow['draft_title'] ?? 'Manual draft'));
                                    $outboxTemplate = trim((string)($outboxRow['template_name'] ?? ''));
                                    $outboxStatus = $manualReturnStatusLabel((string)($outboxRow['status'] ?? ''));
                                    ?>
                                    <div class="rounded-lg border border-slate-800 bg-slate-900/60 px-3 py-2">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="text-xs font-medium text-slate-100"><?= e($outboxPhone !== '' ? $outboxPhone : 'Без телефона') ?></div>
                                                <div class="text-[11px] text-slate-500 mt-1"><?= e($outboxSegment) ?></div>
                                            </div>
                                            <span class="shrink-0 inline-flex items-center rounded-lg border border-slate-700 bg-slate-800 px-2 py-1 text-[10px] text-slate-300"><?= e($outboxStatus) ?></span>
                                        </div>
                                        <div class="text-[11px] text-slate-500 mt-2">
                                            <?= !empty($outboxRow['is_loyalty']) ? 'Loyalty-сценарий' : 'Fallback/manual' ?>
                                            · <?= e((string)($outboxRow['created_at'] ?? '')) ?>
                                            <?php if ($outboxTemplate !== ''): ?> · шаблон <?= e($outboxTemplate) ?><?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="rounded-2xl border border-violet-500/20 bg-gradient-to-br from-violet-500/5 to-slate-900/50 p-5 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-violet-300/80">Что реально сработало</div>
                            <h2 class="text-base font-bold text-white mt-1">Лучшие loyalty-сценарии по возврату</h2>
                        </div>
                        <a href="#loyalty-analytics" class="text-[11px] text-violet-300 hover:text-violet-200 font-medium">К полной аналитике</a>
                    </div>
                    <p class="text-xs text-slate-500">V1.2 замыкает цикл: после запуска draft’ов ресторан сразу видит, какой сценарий вернул paid visits и дал повторную выручку.</p>
                    <?php if ($bestScenarioStats === []): ?>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-6 text-center">
                            <div class="text-sm font-medium text-slate-200">Пока нет накопленной analytics по loyalty-сценариям</div>
                            <div class="text-xs text-slate-500 mt-2">Когда вы начнёте создавать черновики и появятся новые paid orders после них, этот блок покажет самые результативные сценарии.</div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($bestScenarioStats as $scenarioStat): ?>
                                <?php
                                $statLabel = trim((string)($scenarioStat['segment_label'] ?? $scenarioStat['label'] ?? $scenarioStat['segment_type'] ?? 'Сценарий'));
                                $statReturnedGuests = (int)($scenarioStat['returned_guests'] ?? 0);
                                $statReturnedRevenue = (float)($scenarioStat['returned_revenue'] ?? 0);
                                $statReturnRate = (float)($scenarioStat['return_rate'] ?? 0);
                                $statAvgDays = $scenarioStat['avg_days_to_return'] ?? null;
                                ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-100"><?= e($statLabel) ?></div>
                                            <div class="text-[11px] text-slate-500 mt-1"><?= (int)($scenarioStat['drafts_created'] ?? 0) ?> черновиков · <?= (int)($scenarioStat['unique_guests_targeted'] ?? 0) ?> гостей в работе</div>
                                        </div>
                                        <span class="shrink-0 inline-flex items-center rounded-lg border border-violet-500/20 bg-violet-500/10 px-2 py-1 text-[11px] font-semibold text-violet-200"><?= e(number_format($statReturnedRevenue, 0, '.', ' ')) ?> ₽</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2 mt-3 text-[11px]">
                                        <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-300">вернулось гостей: <?= $statReturnedGuests ?></span>
                                        <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-300">доля возврата: <?= e(number_format($statReturnRate * 100, 1, '.', ' ')) ?>%</span>
                                        <?php if ($statAvgDays !== null): ?>
                                            <span class="inline-flex items-center rounded-lg border border-slate-700 bg-slate-900/70 px-2 py-1 text-slate-300">ср. возврат: <?= e(number_format((float)$statAvgDays, 1, '.', ' ')) ?> дн.</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <?php if (!$crmGuestReturnEnabled): ?>
            <div class="rounded-2xl border border-slate-700 bg-slate-900/50 px-4 py-5 text-sm text-slate-400">
                <p class="font-medium text-slate-200">Возврат гостей выключен</p>
                <p class="text-xs text-slate-500 mt-2 max-w-xl">Автоматизированные loyalty-сценарии и быстрые черновики скрыты. База гостей, ручные сообщения и CRM-история ниже остаются доступными.</p>
                <a href="/restaurant/settings.php#crm-return-settings" class="inline-flex mt-3 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold">Открыть настройки CRM</a>
            </div>
            <?php endif; ?>

            <?php if ($crmGuestReturnEnabled): ?>
            <div class="rounded-2xl border border-amber-500/25 bg-gradient-to-br from-amber-500/5 to-slate-900/40 p-5 md:p-6 space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold text-white">Быстрый возврат неактивных гостей</h2>
                        <p class="text-xs text-slate-500 mt-1 max-w-xl">
                            Это простой fallback-сценарий для гостей без визита <?= (int)$inactiveThresholdDays ?>+ дн. Если нужен более точный loyalty-подход, используйте готовые loyalty-сценарии ниже.
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

            <?php if ($loyaltyRetentionScenarios !== []): ?>
            <div id="loyalty-scenarios" class="rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/5 to-slate-900/40 p-5 md:p-6 space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-white">1. Готовые loyalty-сценарии и кандидаты</h2>
                        <p class="text-xs text-slate-500 mt-1 max-w-2xl">Это основной рабочий блок loyalty CRM. Здесь видно, кому подходит сценарий, зачем его запускать, какой текст уйдёт в черновик и каких гостей можно вернуть прямо сейчас.</p>
                    </div>
                    <a href="/restaurant/crm_campaigns.php" class="inline-flex items-center px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">К loyalty-кампаниям</a>
                </div>

                <?php if ($loyaltyTemplateLibrary !== []): ?>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 space-y-3">
                    <div class="text-sm font-semibold text-slate-100">Библиотека retention-шаблонов</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        <?php foreach ($loyaltyTemplateLibrary as $templateKey => $templateCfg): ?>
                            <div class="rounded-xl border border-slate-800 bg-slate-900/70 px-3 py-3">
                                <div class="text-sm font-medium text-slate-100"><?= e((string)($templateCfg['name'] ?? $templateKey)) ?></div>
                                <div class="text-[11px] text-slate-500 mt-1"><?= e((string)($templateCfg['purpose'] ?? '')) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                    <?php foreach ($loyaltyRetentionScenarios as $segmentType => $scenario): ?>
                        <?php
                        $scenarioCount = (int)($scenario['count'] ?? 0);
                        $candidates = is_array($scenario['candidates'] ?? null) ? $scenario['candidates'] : [];
                        ?>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-100"><?= e((string)($scenario['label'] ?? $segmentType)) ?></div>
                                    <div class="text-xs text-slate-500 mt-1"><?= e((string)($scenario['description'] ?? '')) ?></div>
                                </div>
                                <span class="shrink-0 inline-flex items-center rounded-lg bg-emerald-500/10 border border-emerald-500/20 px-2 py-1 text-xs font-semibold text-emerald-200"><?= $scenarioCount ?></span>
                            </div>

                            <div class="grid grid-cols-1 gap-2 text-[11px]">
                                <?php if (!empty($scenario['who'])): ?>
                                    <div class="rounded-lg bg-slate-900/60 border border-slate-800 px-3 py-2 text-slate-300">
                                        <span class="text-slate-500">Кому подходит:</span>
                                        <span class="ml-1"><?= e((string)$scenario['who']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($scenario['goal'])): ?>
                                    <div class="rounded-lg bg-slate-900/60 border border-slate-800 px-3 py-2 text-slate-300">
                                        <span class="text-slate-500">Зачем запускать:</span>
                                        <span class="ml-1"><?= e((string)$scenario['goal']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($scenario['offer_framing'])): ?>
                                    <div class="rounded-lg bg-slate-900/60 border border-slate-800 px-3 py-2 text-slate-300">
                                        <span class="text-slate-500">Как подать оффер:</span>
                                        <span class="ml-1"><?= e((string)$scenario['offer_framing']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($scenario['recommended_template_key']) && isset($loyaltyTemplateLibrary[$scenario['recommended_template_key']])): ?>
                                    <div class="rounded-lg bg-slate-900/60 border border-slate-800 px-3 py-2 text-slate-300">
                                        <span class="text-slate-500">Рекомендуемый шаблон:</span>
                                        <span class="ml-1"><?= e((string)($loyaltyTemplateLibrary[$scenario['recommended_template_key']]['name'] ?? $scenario['recommended_template_key'])) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($scenarioCount > 0 && !is_demo_mode()): ?>
                                <form method="post" class="space-y-2">
                                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="bulk_loyalty_retention_drafts">
                                    <input type="hidden" name="segment_type" value="<?= e($segmentType) ?>">
                                    <?php if ($loyaltyTemplateLibrary !== []): ?>
                                    <div>
                                        <label class="block text-[11px] text-slate-500 mb-1">Шаблон для массового черновика</label>
                                        <select name="template_key" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs text-slate-100">
                                            <?php foreach ($loyaltyTemplateLibrary as $templateKey => $templateCfg): ?>
                                                <option value="<?= e($templateKey) ?>" <?= (($scenario['recommended_template_key'] ?? '') === $templateKey) ? 'selected' : '' ?>><?= e((string)($templateCfg['name'] ?? $templateKey)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex justify-end">
                                        <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-600/80 hover:bg-emerald-500 text-white text-xs font-semibold">Сформировать черновики всем подходящим</button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if ($candidates === []): ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-5 text-center">
                                    <div class="text-sm text-slate-400">Сейчас подходящих гостей нет</div>
                                    <div class="text-[11px] text-slate-600 mt-2">Как только появятся гости с нужным паттерном визитов и бонусов, они появятся в этом сценарии.</div>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($candidates as $cand): ?>
                                        <?php
                                        $gid = (int)($cand['crm_guest_id'] ?? 0);
                                        $days = (int)($cand['days_since_paid_visit'] ?? 0);
                                        $balance = (int)($cand['loyalty_balance'] ?? 0);
                                        $visits = (int)($cand['visits_count'] ?? 0);
                                        $avg = (float)($cand['avg_check'] ?? 0);
                                        $totalSpent = (float)($cand['total_spent'] ?? 0);
                                        $lastSpend = trim((string)($cand['last_bonus_spend_at'] ?? ''));
                                        ?>
                                        <div class="rounded-xl border border-slate-800 bg-slate-900/70 px-4 py-3 space-y-2">
                                            <div class="flex flex-wrap items-start justify-between gap-2">
                                                <div>
                                                    <div class="text-sm font-medium text-slate-100"><?= e((string)($cand['phone'] ?? '')) ?></div>
                                                    <div class="text-[11px] text-slate-500 mt-1"><?= e((string)($cand['reason_text'] ?? '')) ?></div>
                                                </div>
                                                <div class="text-right text-[11px] text-slate-400">
                                                    <div>Баланс: <span class="text-emerald-300 font-semibold"><?= $balance ?></span></div>
                                                    <div>Визиты: <?= $visits ?></div>
                                                </div>
                                            </div>
                                            <div class="text-[11px] text-slate-500">
                                                <?= $days > 0 ? ('Последний paid визит: ' . $days . ' дн. назад') : 'Последний paid визит: недавно' ?>
                                                <?php if ($avg > 0): ?> · Ср. чек <?= e(number_format($avg, 0, '.', ' ')) ?> ₽<?php endif; ?>
                                                <?php if ($totalSpent > 0): ?> · Всего <?= e(number_format($totalSpent, 0, '.', ' ')) ?> ₽<?php endif; ?>
                                                <?php if ($lastSpend !== ''): ?> · Последнее списание <?= e(date('d.m.Y', strtotime($lastSpend))) ?><?php endif; ?>
                                            </div>
                                            <?php if (!empty($cand['recommended_template_name'])): ?>
                                                <div class="text-[11px] text-slate-500">По умолчанию: <?= e((string)$cand['recommended_template_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($cand['draft_title'])): ?>
                                                <div class="text-[11px] text-emerald-300 font-medium">Черновик: <?= e((string)$cand['draft_title']) ?></div>
                                            <?php endif; ?>
                                            <div class="rounded-lg bg-slate-950/70 px-3 py-2 text-xs text-slate-300 leading-relaxed">
                                                <div class="text-[11px] uppercase tracking-wide text-slate-500 mb-1">Рекомендуемый текст сообщения</div>
                                                <?= e((string)($cand['message_text'] ?? '')) ?>
                                            </div>
                                            <?php if (!empty($cand['scenario_offer_framing'])): ?>
                                                <div class="text-[11px] text-slate-500">Рекомендуемая подача: <?= e((string)$cand['scenario_offer_framing']) ?></div>
                                            <?php endif; ?>
                                            <div class="flex flex-wrap gap-2">
                                                <a href="<?= e($crmQueryBase(['profile' => $gid, 'history' => null, 'compose' => null])) ?>#crm-guest-profile" class="px-3 py-2 rounded-xl bg-sky-600/80 hover:bg-sky-500 text-white text-xs font-semibold">Профиль гостя</a>
                                                <?php if (!is_demo_mode()): ?>
                                                    <form method="post" class="space-y-2">
                                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                        <input type="hidden" name="action" value="create_loyalty_retention_draft">
                                                        <input type="hidden" name="segment_type" value="<?= e($segmentType) ?>">
                                                        <input type="hidden" name="guest_id" value="<?= $gid ?>">
                                                        <?php if (!empty($cand['template_options']) && is_array($cand['template_options'])): ?>
                                                            <div>
                                                                <label class="block text-[11px] text-slate-500 mb-1">Шаблон для этого гостя</label>
                                                                <select name="template_key" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs text-slate-100 min-w-[220px]">
                                                                    <?php foreach ($cand['template_options'] as $templateKey => $templateCfg): ?>
                                                                        <option value="<?= e((string)$templateKey) ?>" <?= (($cand['recommended_template_key'] ?? '') === $templateKey) ? 'selected' : '' ?>><?= e((string)($templateCfg['name'] ?? $templateKey)) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                        <?php endif; ?>
                                                        <button type="submit" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold">Создать черновик</button>
                                                    </form>
                                                <?php endif; ?>
                                                <a href="<?= e($crmQueryBase(['history' => $gid, 'compose' => null])) ?>#guests-return" class="px-3 py-2 rounded-xl bg-slate-800 text-slate-200 text-xs font-medium">История</a>
                                                <a href="<?= e($crmQueryBase(['compose' => $gid, 'history' => null])) ?>#guests-return" class="px-3 py-2 rounded-xl bg-slate-800 text-slate-200 text-xs font-medium">Сообщение вручную</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($loyaltyScenarioAnalytics !== []): ?>
            <div id="loyalty-analytics" class="rounded-2xl border border-cyan-500/20 bg-gradient-to-br from-cyan-500/5 to-slate-900/40 p-5 md:p-6 space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-white">3. Что реально работает</h2>
                        <p class="text-xs text-slate-500 mt-1 max-w-2xl">Здесь видно, какие loyalty-сценарии дают не только черновики, но и реальные confirmed paid возвраты. Аналитика считается по первому paid order после создания черновика.</p>
                    </div>
                    <div class="text-[11px] text-cyan-300">Модель attribution: первый confirmed paid order после черновика</div>
                </div>

                <?php if ($loyaltyScenarioDraftsTotal <= 0): ?>
                <div class="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-8 text-center">
                    <div class="text-sm text-slate-400">Аналитика loyalty-сценариев появится после первых черновиков</div>
                    <div class="text-[11px] text-slate-600 mt-2">Сначала создайте хотя бы один loyalty-черновик выше. После этого блок начнёт считать возвраты и выручку.</div>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto rounded-xl border border-slate-800">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-800">
                                <th class="p-3">Сценарий</th>
                                <th class="p-3">Черновики</th>
                                <th class="p-3">Гости</th>
                                <th class="p-3">Вернулось</th>
                                <th class="p-3">Return rate</th>
                                <th class="p-3">Заказы</th>
                                <th class="p-3">Выручка</th>
                                <th class="p-3">Ср. время</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loyaltyScenarioAnalytics as $segmentType => $stat): ?>
                                <tr class="border-b border-slate-800/80">
                                    <td class="p-3">
                                        <div class="text-slate-100 font-medium"><?= e((string)($stat['label'] ?? $segmentType)) ?></div>
                                        <div class="text-[11px] text-slate-500"><?= e((string)($loyaltySegmentCatalog[$segmentType]['description'] ?? '')) ?></div>
                                    </td>
                                    <td class="p-3 text-slate-300"><?= (int)($stat['drafts_created'] ?? 0) ?></td>
                                    <td class="p-3 text-slate-300"><?= (int)($stat['unique_guests_targeted'] ?? 0) ?></td>
                                    <td class="p-3 text-slate-300"><?= (int)($stat['returned_guests'] ?? 0) ?></td>
                                    <td class="p-3 text-emerald-300 font-medium"><?= e(number_format(((float)($stat['return_rate'] ?? 0)) * 100, 1, '.', ' ')) ?>%</td>
                                    <td class="p-3 text-slate-300"><?= (int)($stat['paid_orders_after_draft'] ?? 0) ?></td>
                                    <td class="p-3 text-slate-100"><?= e(number_format((float)($stat['returned_revenue'] ?? 0), 0, '.', ' ')) ?> ₽</td>
                                    <td class="p-3 text-slate-400">
                                        <?= ($stat['avg_days_to_return'] ?? null) !== null ? e(number_format((float)$stat['avg_days_to_return'], 1, '.', ' ')) . ' дн.' : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                    <div class="text-[10px] text-slate-600 mt-1">*по оплаченным заказам с CRM-привязкой гостя</div>
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
                    <p class="text-sm text-slate-500 max-w-md mx-auto">Когда гости оставят телефон в заказе (и будет согласие), они появятся в CRM. Заказы с CRM-привязкой гостя дадут суммы и историю.</p>
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

        <section id="crm-secondary-tools" class="section reveal rounded-2xl border border-slate-800/80 bg-slate-900/40 p-5">
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-white">4. Дополнительные CRM инструменты</h2>
                    <p class="text-xs text-slate-500 mt-1 max-w-2xl">Ниже остаются вспомогательные блоки: идеи из отзывов, feedback->upsell черновики и старые comeback widgets. Они дополняют основной loyalty CRM flow, но не заменяют его.</p>
                </div>
                <a href="#crm-outbox" class="inline-flex items-center px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold">Перейти к исходящим</a>
            </div>
        </section>

        <?php if (!is_demo_mode()): ?>
        <section id="feedback-suggestions" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Сценарии возврата из отзывов</h3>
            <p class="text-xs text-slate-500 mb-4">Дополнительный источник идей: черновики на основе отзывов гостей. Авто-отправка отключена.</p>
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
                <p class="mt-4 text-right text-xs text-slate-500">CRM доступен на тарифе GROWTH. <a href="/restaurant/activate.php?plan=growth" class="text-amber-400 hover:underline">Открыть тариф</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($feedbackUpsellCrmDrafts)): ?>
        <section id="feedback-upsell-drafts" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Черновики из отзывов и upsell</h3>
            <p class="text-xs text-slate-500 mb-4">CRM-черновики, созданные после принятия upsell-предложений из отзывов. Это вспомогательный поток, не основной loyalty retention.</p>
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
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Подсказки по возврату гостей</h3>
            <p class="text-xs text-slate-500 mb-4">Ручные идеи для возврата гостей, которые давно не были. Используйте их как дополняющий инструмент рядом с loyalty-сценариями.</p>
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
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium btn-motion">Создать comeback-черновик</button>
                        </form>
                        <?php elseif (!$crmEnabled): ?>
                        <span class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-500 text-xs cursor-not-allowed" title="CRM на тарифе GROWTH">Создать comeback-черновик</span>
                        <?php else: ?>
                        <a href="/restaurant/crm_campaigns.php" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-xs font-medium btn-motion">Создать comeback-черновик</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if (!empty($guestSegments) || !empty($simpleRetentionOpps)): ?>
        <section class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Простые retention-возможности</h3>
            <p class="text-xs text-slate-500 mb-2">
                Базовые подсказки по гостям, которые были раньше, но давно не возвращались. Используйте их как ручной fallback, если основной loyalty-сценарий не подходит.
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
                    Создать CRM-черновики
                </button>
            </form>
            <?php else: ?>
            <p class="mt-4 text-right text-xs text-slate-500">CRM доступен на тарифе GROWTH. <a href="/restaurant/activate.php?plan=growth" class="text-amber-400 hover:underline">Открыть тариф</a></p>
            <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($comebackCandidates)): ?>
        <section id="comeback-candidates" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <h3 class="text-lg font-semibold text-slate-100 mb-2">Сильные кандидаты на возврат</h3>
            <p class="text-xs text-slate-500 mb-4">Гости с потенциалом возврата по старому comeback-engine. Это дополнительный источник идей, без авто-отправки.</p>
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
                            <button type="button" class="js-preview-message px-2 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-xs text-slate-200" data-message="<?= e($c['suggested_message']) ?>">Предпросмотр</button>
                            <?php if ($crmEnabled && !is_demo_mode()): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="create_comeback_draft">
                                <input type="hidden" name="guest_contact" value="<?= e($c['guest_contact']) ?>">
                                <input type="hidden" name="guest_name" value="<?= e($c['guest_name'] ?? '') ?>">
                                <input type="hidden" name="suggested_offer" value="<?= e($c['suggested_offer']) ?>">
                                <input type="hidden" name="suggested_message" value="<?= e($c['suggested_message']) ?>">
                                <input type="hidden" name="score" value="<?= (int)$c['score'] ?>">
                                <button type="submit" class="px-2 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-xs text-white">Создать comeback-черновик</button>
                            </form>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="create_single_retention_draft">
                                <input type="hidden" name="guest_contact" value="<?= e($c['guest_contact']) ?>">
                                <input type="hidden" name="guest_name" value="<?= e($c['guest_name'] ?? '') ?>">
                                <input type="hidden" name="message" value="<?= e($c['suggested_message']) ?>">
                                <button type="submit" class="px-2 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-xs text-slate-200">Создать retention-черновик</button>
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
                <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-medium btn-motion">Создать comeback-черновики пакетно</button>
            </form>
            <?php elseif (!$crmEnabled && count($comebackCandidates) > 0): ?>
            <p class="mt-4 text-right text-xs text-slate-500">CRM доступен на тарифе GROWTH. <a href="/restaurant/activate.php?plan=growth" class="text-amber-400 hover:underline">Открыть тариф</a></p>
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
            <h2 class="text-2xl font-bold mb-1">2. Черновики и исходящие</h2>
            <p class="text-xs text-slate-500">Это рабочая очередь CRM: здесь видно, что нужно отправить вручную сейчас, что ещё остаётся в черновиках и какие сообщения уже дали результат.</p>
        </header>

        <section class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4 md:p-5 space-y-4">
            <div class="grid grid-cols-2 xl:grid-cols-5 gap-3">
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Нужно отправить сейчас</div>
                    <div class="text-2xl font-bold text-amber-300 mt-1"><?= (int)($manualReturnOutboxSummary['ready_manual'] ?? 0) + (int)($manualReturnOutboxSummary['pending'] ?? 0) ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Готовы к ручной отправке</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Черновики</div>
                    <div class="text-2xl font-bold text-cyan-300 mt-1"><?= (int)($manualReturnOutboxSummary['draft'] ?? 0) ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Нужна проверка текста</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Отправлено / обработано</div>
                    <div class="text-2xl font-bold text-emerald-300 mt-1"><?= (int)($manualReturnOutboxSummary['processed'] ?? 0) ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Уже отправлены или обработаны</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Отменено / ошибка</div>
                    <div class="text-2xl font-bold text-rose-300 mt-1"><?= (int)($manualReturnOutboxSummary['canceled'] ?? 0) + (int)($manualReturnOutboxSummary['failed'] ?? 0) ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Не в работе</div>
                </div>
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">Loyalty-сценарии</div>
                    <div class="text-2xl font-bold text-violet-300 mt-1"><?= (int)($manualReturnOutboxSummary['loyalty_rows'] ?? 0) ?></div>
                    <div class="text-[11px] text-slate-500 mt-1">Из loyalty-сценариев</div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <div class="rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/5 to-slate-950/60 p-4 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-100">Нужно отправить сейчас</h3>
                            <p class="text-[11px] text-slate-500 mt-1">Сообщения, которые уже готовы к ручной отправке или висят в pending.</p>
                        </div>
                        <a href="<?= e($crmQueryBase(['status' => 'ready_manual'])) ?>#crm-outbox-table" class="text-[11px] text-amber-300 hover:text-amber-200 font-medium">Открыть список</a>
                    </div>
                    <?php if ($sendBoardReadyRows === []): ?>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-6 text-center">
                            <div class="text-sm font-medium text-slate-200">Сейчас нет сообщений к ручной отправке</div>
                            <div class="text-[11px] text-slate-500 mt-2">Когда loyalty-сценарий дойдёт до `ready_manual` или `pending`, он появится здесь.</div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($sendBoardReadyRows as $boardRow): ?>
                                <?php $board = $crmOutboxBoardMeta($boardRow); ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3 space-y-2">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-100"><?= e((string)($boardRow['phone'] ?? 'Без телефона')) ?></div>
                                            <div class="text-[11px] text-slate-500 mt-1"><?= e($board['segment_label'] !== '' ? $board['segment_label'] : ($board['reason'] !== '' ? $board['reason'] : 'Manual return')) ?></div>
                                        </div>
                                        <span class="inline-flex items-center rounded-lg border border-amber-500/20 bg-amber-500/10 px-2 py-1 text-[10px] font-semibold text-amber-200"><?= e($board['status_ui']) ?></span>
                                    </div>
                                    <div class="text-xs text-slate-300 bg-slate-900/70 border border-slate-800 rounded-lg px-3 py-2 whitespace-pre-wrap break-words"><?= $board['message_text'] !== '' ? e($board['message_text']) : '—' ?></div>
                                    <div class="text-[11px] text-slate-500">
                                        <?= e((string)($boardRow['scheduled_at'] ?? '')) ?>
                                        <?php if ($board['template_name'] !== ''): ?> · шаблон <?= e($board['template_name']) ?><?php endif; ?>
                                    </div>
                                    <?php if ($board['returned']): ?>
                                        <div class="text-[11px] text-emerald-200">Гость уже вернулся: paid order #<?= $board['return_order_id'] ?> · <?= e(number_format((float)$board['return_order_total'], 0, '.', ' ')) ?> ₽</div>
                                    <?php endif; ?>
                                    <?php if ($crmEnabled || (($boardRow['template'] ?? '') === 'manual_return')): ?>
                                        <div class="flex flex-wrap gap-2 pt-1">
                                            <a href="<?= e($crmQueryBase(['profile' => (int)($boardRow['guest_id'] ?? 0), 'history' => null, 'compose' => null])) ?>#crm-guest-profile" class="px-3 py-1.5 rounded-lg bg-sky-600/80 hover:bg-sky-500 text-white text-[11px] font-semibold">Профиль гостя</a>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="action" value="send_manual">
                                                <input type="hidden" name="id" value="<?= (int)($boardRow['id'] ?? 0) ?>">
                                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-semibold">Отметить отправленным</button>
                                            </form>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="action" value="cancel">
                                                <input type="hidden" name="id" value="<?= (int)($boardRow['id'] ?? 0) ?>">
                                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-medium">Отменить</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="rounded-2xl border border-cyan-500/20 bg-gradient-to-br from-cyan-500/5 to-slate-950/60 p-4 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-100">Черновики</h3>
                            <p class="text-[11px] text-slate-500 mt-1">Retention-сообщения, которые ещё стоит проверить перед ручной отправкой.</p>
                        </div>
                        <a href="<?= e($crmQueryBase(['status' => 'draft'])) ?>#crm-outbox-table" class="text-[11px] text-cyan-300 hover:text-cyan-200 font-medium">Открыть список</a>
                    </div>
                    <?php if ($sendBoardDraftRows === []): ?>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-6 text-center">
                            <div class="text-sm font-medium text-slate-200">Черновиков сейчас нет</div>
                            <div class="text-[11px] text-slate-500 mt-2">Создайте one-click draft из приоритетной очереди или из loyalty-сценария выше.</div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($sendBoardDraftRows as $boardRow): ?>
                                <?php $board = $crmOutboxBoardMeta($boardRow); ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3 space-y-2">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-100"><?= e((string)($boardRow['phone'] ?? 'Без телефона')) ?></div>
                                            <div class="text-[11px] text-slate-500 mt-1"><?= e($board['segment_label'] !== '' ? $board['segment_label'] : ($board['reason'] !== '' ? $board['reason'] : 'Manual return')) ?></div>
                                        </div>
                                        <span class="inline-flex items-center rounded-lg border border-cyan-500/20 bg-cyan-500/10 px-2 py-1 text-[10px] font-semibold text-cyan-200"><?= e($board['status_ui']) ?></span>
                                    </div>
                                    <div class="text-xs text-slate-300 bg-slate-900/70 border border-slate-800 rounded-lg px-3 py-2 whitespace-pre-wrap break-words"><?= $board['message_text'] !== '' ? e($board['message_text']) : '—' ?></div>
                                    <div class="text-[11px] text-slate-500">
                                        <?= e((string)($boardRow['scheduled_at'] ?? '')) ?>
                                        <?php if ($board['template_name'] !== ''): ?> · шаблон <?= e($board['template_name']) ?><?php endif; ?>
                                    </div>
                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <a href="<?= e($crmQueryBase(['profile' => (int)($boardRow['guest_id'] ?? 0), 'history' => null, 'compose' => null])) ?>#crm-guest-profile" class="px-3 py-1.5 rounded-lg bg-sky-600/80 hover:bg-sky-500 text-white text-[11px] font-semibold">Профиль гостя</a>
                                        <a href="<?= e($crmQueryBase(['status' => 'draft'])) ?>#crm-outbox-table" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-medium">Открыть в журнале</a>
                                        <?php if ($crmEnabled || (($boardRow['template'] ?? '') === 'manual_return')): ?>
                                            <form method="post" class="inline">
                                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="action" value="send_manual">
                                                <input type="hidden" name="id" value="<?= (int)($boardRow['id'] ?? 0) ?>">
                                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-semibold">Отметить отправленным</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/5 to-slate-950/60 p-4 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-100">Уже отправлено / обработано</h3>
                            <p class="text-[11px] text-slate-500 mt-1">Manual sends и обработанные сообщения с marker’ом результата, если после touch был paid order.</p>
                        </div>
                        <a href="<?= e($crmQueryBase(['status' => 'processed'])) ?>#crm-outbox-table" class="text-[11px] text-emerald-300 hover:text-emerald-200 font-medium">Открыть список</a>
                    </div>
                    <?php if ($sendBoardDoneRows === []): ?>
                        <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-6 text-center">
                            <div class="text-sm font-medium text-slate-200">Пока нет обработанных retention-сообщений</div>
                            <div class="text-[11px] text-slate-500 mt-2">Когда сообщения начнут проходить через ручную отправку, здесь появится компактный журнал с результатом.</div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($sendBoardDoneRows as $boardRow): ?>
                                <?php $board = $crmOutboxBoardMeta($boardRow); ?>
                                <div class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3 space-y-2">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-100"><?= e((string)($boardRow['phone'] ?? 'Без телефона')) ?></div>
                                            <div class="text-[11px] text-slate-500 mt-1"><?= e($board['segment_label'] !== '' ? $board['segment_label'] : ($board['reason'] !== '' ? $board['reason'] : 'Manual return')) ?></div>
                                        </div>
                                        <span class="inline-flex items-center rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2 py-1 text-[10px] font-semibold text-emerald-200"><?= e($board['status_ui']) ?></span>
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        <?= e((string)($boardRow['scheduled_at'] ?? '')) ?>
                                        <?php if ($board['template_name'] !== ''): ?> · шаблон <?= e($board['template_name']) ?><?php endif; ?>
                                    </div>
                                    <?php if ($board['returned']): ?>
                                        <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-3 py-2 text-[11px] text-emerald-100">
                                            Гость вернулся после retention touch:
                                            paid order #<?= $board['return_order_id'] ?>
                                            · <?= e(number_format((float)$board['return_order_total'], 0, '.', ' ')) ?> ₽
                                            <?php if ($board['days_to_return'] !== null): ?> · через <?= e(number_format((float)$board['days_to_return'], 0, '.', ' ')) ?> дн.<?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-[11px] text-slate-500">Нового confirmed paid order после этого сообщения пока не видно.</div>
                                    <?php endif; ?>
                                    <div class="pt-1">
                                        <a href="<?= e($crmQueryBase(['profile' => (int)($boardRow['guest_id'] ?? 0), 'history' => null, 'compose' => null])) ?>#crm-guest-profile" class="px-3 py-1.5 rounded-lg bg-sky-600/80 hover:bg-sky-500 text-white text-[11px] font-semibold">Профиль гостя</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

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

        <section id="crm-outbox-table" class="section reveal dashboard-card card-motion bg-slate-900/80 border border-slate-800 rounded-3xl p-4">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-slate-100">Детальный журнал outbox</h3>
                <p class="text-xs text-slate-500 mt-1">Ниже остаётся исходный детальный список по выбранному статусу: он полезен для проверки payload, копирования текста и ручной операционной работы.</p>
            </div>
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <svg class="empty-state-icon mx-auto text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <div class="empty-state-title"><?= e($outboxEmptyTitle) ?></div>
                    <div class="empty-state-text"><?= e($outboxEmptyText) ?></div>
                    <?php if ($crmEnabled): ?><a href="/restaurant/crm_campaigns.php" class="inline-block px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium btn-motion">Создать кампанию</a><?php else: ?><a href="/restaurant/activate.php?plan=growth" class="inline-block px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-sm font-medium">Открыть тариф GROWTH</a><?php endif; ?>
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
                                        $payloadTemplateName = trim((string)($payload['template_name'] ?? ''));

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
                                        <?php if ($payloadTemplateName !== ''): ?>
                                            <div class="text-[11px] text-slate-500 mt-1">Шаблон: <?= e($payloadTemplateName) ?></div>
                                        <?php endif; ?>
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
