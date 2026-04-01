<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/bootstrap.php';

$pdo = db();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function guest_e($v): string {
    return function_exists('e') ? e($v) : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function guest_require_login(): int {
    $gid = (int)($_SESSION['guest_id'] ?? 0);
    if ($gid <= 0) {
        header("Location: /guest/login.php");
        exit;
    }
    return $gid;
}

function guest_get(PDO $pdo, int $gid): ?array {
    $st = $pdo->prepare("SELECT id, phone, name FROM guests WHERE id = :id LIMIT 1");
    $st->execute([':id' => $gid]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    return $g ?: null;
}

function guest_loyalty_balance(PDO $pdo, int $restaurantId, string $phone, ?int $guestId = null): int {
    // Single ledger: only guest_loyalty_accounts. No legacy reads.
    if ($guestId !== null && $guestId > 0 && function_exists('guest_loyalty_balance_by_guest_rest')) {
        return guest_loyalty_balance_by_guest_rest($pdo, $restaurantId, $guestId);
    }
    return 0;
}

$guestId = guest_require_login();
$guest = guest_get($pdo, $guestId);
if (!$guest) {
    unset($_SESSION['guest_id']);
    header("Location: /guest/login.php");
    exit;
}


$stmt = $pdo->prepare("
    SELECT
      gc.id,
      gc.restaurant_id,
      gc.public_uid,
      gc.token,
      gc.status,
      r.name as restaurant_name,
      r.qr_theme as qr_theme
    FROM guest_cards gc
    JOIN restaurants r ON r.id = gc.restaurant_id
    WHERE gc.guest_id = :gid
      AND gc.status = 'active'
    ORDER BY gc.id DESC
");
$stmt->execute([':gid' => $guestId]);
$cards = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Soft loyalty gating: per-restaurant plan check so guest sees safe state when loyalty disabled.
$loyaltyEnabledForRestaurant = [];
if (file_exists(__DIR__ . '/../../app/subscription_plans.php')) {
    require_once __DIR__ . '/../../app/subscription_plans.php';
}
$cardsUi = [];
foreach ($cards as $c) {
    $rid = (int)$c['restaurant_id'];
    $planAllowsLoyalty = true;
    if (function_exists('check_feature') && !check_feature($rid, 'loyalty_enabled')) {
        $planAllowsLoyalty = false;
    }
    $bal = $planAllowsLoyalty ? guest_loyalty_balance($pdo, $rid, (string)$guest['phone'], $guestId) : 0;
    $cardsUi[] = [
        'id' => (int)$c['id'],
        'restaurant_id' => $rid,
        'restaurant_name' => (string)$c['restaurant_name'],
        'public_uid' => (string)$c['public_uid'],
        'token' => (string)$c['token'],
        'balance' => (int)$bal,
        'loyalty_unavailable' => !$planAllowsLoyalty,
    ];
}

$cardsUiJson = json_encode($cardsUi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Wallet</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <style>
    .bg-noise {
      background-image:
        radial-gradient(circle at 20% 10%, rgba(16,185,129,.18), transparent 45%),
        radial-gradient(circle at 80% 0%, rgba(56,189,248,.12), transparent 45%),
        radial-gradient(circle at 70% 80%, rgba(168,85,247,.14), transparent 50%),
        radial-gradient(circle at 10% 90%, rgba(244,63,94,.10), transparent 55%);
    }
    .card {
      position: relative;
      border-radius: 28px;
      overflow: hidden;
      transform: translateZ(0);
      box-shadow: 0 22px 60px rgba(0,0,0,.55);
      border: 1px solid rgba(148,163,184,.16);
    }
    .card::before{
      content:"";
      position:absolute; inset:-2px;
      background: linear-gradient(120deg, transparent 10%, rgba(255,255,255,.12) 33%, transparent 58%);
      transform: translateX(-60%);
      animation: sweep 4.6s ease-in-out infinite;
      pointer-events:none;
      border-radius: 28px;
      opacity:.7;
    }
    @keyframes sweep{ 0%,45%{transform:translateX(-65%)} 70%{transform:translateX(65%)} 100%{transform:translateX(65%)} }

    .track { scroll-snap-type: x mandatory; }
    .slide { scroll-snap-align: center; }

    .no-scrollbar::-webkit-scrollbar { display:none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

    .dot { width: 7px; height: 7px; border-radius: 999px; background: rgba(148,163,184,.35); }
    .dot.active { background: rgba(16,185,129,.95); box-shadow: 0 0 0 4px rgba(16,185,129,.14); }

    .glass {
      background: rgba(2, 6, 23, .62);
      border: 1px solid rgba(148,163,184,.16);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
    }
  </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 bg-noise">

  <div class="max-w-3xl mx-auto px-4 py-6 space-y-4">


    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full glass text-[11px] text-slate-300">
          <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
          Wallet
        </div>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">Карты лояльности</h1>
        <div class="mt-1 text-sm text-slate-400">
          <?= guest_e($guest['name'] ?: 'Гость') ?> · <?= guest_e($guest['phone']) ?>
        </div>
      </div>

      <div class="flex gap-2">
        <a href="/guest/logout.php"
           class="px-3 py-2 rounded-2xl bg-slate-900/70 border border-slate-800 text-xs text-rose-200 hover:bg-slate-800">
          Выйти
        </a>
      </div>
    </div>

    <?php if (!$cardsUi): ?>
      <div class="rounded-3xl glass p-5">
        <div class="text-lg font-semibold">Пока нет ни одной карты</div>
        <div class="text-sm text-slate-400 mt-1">
          Попросите официанта оформить карту на ваш номер телефона — и она появится здесь.
        </div>
        <div class="mt-4 rounded-2xl bg-slate-950/70 border border-slate-800 p-4 text-[11px] text-slate-400">
          Подсказка: номер телефона должен совпадать с тем, который вы указали при входе.
        </div>
      </div>
    <?php else: ?>


      <div class="rounded-3xl glass p-4">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div class="text-sm font-semibold">Ваши карты</div>
          <div class="text-[11px] text-slate-400">Свайпайте влево/вправо</div>
        </div>

        <div id="track"
             class="track no-scrollbar flex gap-4 overflow-x-auto pb-2 -mx-2 px-2"
             style="scroll-padding: 16px;">

        </div>

        <div class="mt-3 flex items-center justify-center gap-2" id="dots"></div>
      </div>

      <!-- Info -->
      <div class="rounded-3xl glass p-4">
        <div class="text-sm font-semibold mb-1">Как это работает</div>
        <div class="text-sm text-slate-400">
          Покажите QR официанту. Он может начислить или списать бонусы именно в этом ресторане.
        </div>
      </div>

    <?php endif; ?>

  </div>

<script>
(function(){
  const cards = <?= $cardsUiJson ?: '[]' ?>;

  const track = document.getElementById('track');
  const dots  = document.getElementById('dots');

  function escapeHtml(str){
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }

  function makeCardGradient(i){
    const sets = [
      'from-emerald-500/35 via-slate-900 to-slate-950',
      'from-sky-500/30 via-slate-900 to-slate-950',
      'from-fuchsia-500/25 via-slate-900 to-slate-950',
      'from-rose-500/25 via-slate-900 to-slate-950',
      'from-amber-400/20 via-slate-900 to-slate-950',
    ];
    return sets[i % sets.length];
  }

  function render(){
    if (!track || !dots) return;

    track.innerHTML = '';
    dots.innerHTML = '';

    cards.forEach((c, idx) => {
      const slide = document.createElement('div');
      slide.className = 'slide w-[320px] sm:w-[380px] flex-shrink-0';
      slide.innerHTML = `
        <div class="card bg-gradient-to-br ${makeCardGradient(idx)} p-4">
          <div class="relative z-10">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-[11px] text-slate-300 uppercase tracking-wide">Loyalty Pass</div>
                <div class="mt-1 text-xl font-semibold">${escapeHtml(c.restaurant_name)}</div>
                <div class="mt-1 text-[11px] text-slate-400">ID: ${escapeHtml(String(c.public_uid).slice(0,8))}…</div>
              </div>

              <div class="text-right">
                ${c.loyalty_unavailable ? `
                <div class="text-[10px] text-slate-400">Лояльность</div>
                <div class="text-xs text-slate-400 mt-0.5 max-w-[140px]">Программа лояльности сейчас недоступна для этого ресторана</div>
                ` : `
                <div class="text-[10px] text-slate-400">Баланс</div>
                <div class="text-2xl font-semibold text-white leading-tight">${Number(c.balance||0)}</div>
                <div class="text-[11px] text-slate-400 -mt-0.5">бонусов</div>
                `}
              </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 items-center">
              <div class="rounded-2xl bg-white p-3 flex items-center justify-center">
                <div id="qr_${c.id}" class="w-[132px] h-[132px]"></div>
              </div>

              <div class="space-y-2">
                <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-3">
                  <div class="text-[11px] text-slate-400">Покажите QR официанту</div>
                  <div class="text-sm mt-1 text-slate-100 font-medium">Начисление / списание</div>
                  <div class="text-[11px] text-slate-500 mt-1 leading-snug">
                    Бонусы действуют только внутри этого ресторана.
                  </div>
                </div>

                <a href="/guest/apple_wallet.php?card_id=${c.id}"
                   class="inline-flex items-center justify-center w-full px-4 py-3 rounded-2xl bg-black text-white text-sm font-semibold hover:opacity-90">
                  Добавить в Apple Wallet
                </a>

                <button type="button"
                        data-token="${escapeHtml(c.token)}"
                        class="copyBtn inline-flex items-center justify-center w-full px-4 py-2.5 rounded-2xl bg-slate-900/70 border border-slate-800 text-xs text-slate-200 hover:bg-slate-800">
                  Скопировать код
                </button>
              </div>
            </div>
          </div>
        </div>
      `;
      track.appendChild(slide);

      const d = document.createElement('div');
      d.className = 'dot' + (idx===0 ? ' active' : '');
      d.dataset.idx = String(idx);
      d.title = 'Карта ' + (idx+1);
      d.style.cursor = 'pointer';
      d.onclick = () => {
        const el = track.children[idx];
        if (el) el.scrollIntoView({behavior:'smooth', inline:'center', block:'nearest'});
      };
      dots.appendChild(d);
    });


    cards.forEach((c) => {
      const box = document.getElementById('qr_' + c.id);
      if (!box) return;
      box.innerHTML = '';
      new QRCode(box, { text: String(c.token), width: 132, height: 132 });
    });


    document.querySelectorAll('.copyBtn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const token = btn.getAttribute('data-token') || '';
        const old = btn.textContent;
        try {
          await navigator.clipboard.writeText(token);
          btn.textContent = 'Скопировано ✅';
        } catch(e) {
          btn.textContent = 'Не удалось скопировать';
        }
        setTimeout(()=>btn.textContent = old, 1200);
      });
    });
  }

  function markActiveDot(){
    if (!track || !dots) return;
    const children = Array.from(track.children);
    if (!children.length) return;

    const center = track.scrollLeft + track.clientWidth / 2;
    let bestIdx = 0;
    let bestDist = Infinity;

    children.forEach((el, idx) => {
      const rect = el.getBoundingClientRect();
      const elCenter = el.offsetLeft + el.clientWidth/2;
      const dist = Math.abs(elCenter - center);
      if (dist < bestDist) { bestDist = dist; bestIdx = idx; }
    });

    Array.from(dots.children).forEach((dot, idx) => {
      dot.classList.toggle('active', idx === bestIdx);
    });
  }

  render();
  if (track) {
    track.addEventListener('scroll', () => requestAnimationFrame(markActiveDot), {passive:true});
    window.addEventListener('resize', () => requestAnimationFrame(markActiveDot));
  }
})();
</script>

</body>
</html>
