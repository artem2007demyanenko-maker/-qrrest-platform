<?php

declare(strict_types=1);

if (!function_exists('qr_cookie_consent_snippet')) {
    function qr_cookie_consent_snippet(): string
    {
        return <<<'HTML'
<div id="qr-cookie-consent-root" class="qr-cookie-consent-root" hidden>
  <button type="button" id="qr-cookie-settings-fab" class="qr-cookie-settings-fab" aria-label="Открыть настройки cookies" aria-controls="qr-cookie-modal" aria-expanded="false">
    Настройки cookies
  </button>

  <div id="qr-cookie-backdrop" class="qr-cookie-backdrop" hidden tabindex="-1" aria-hidden="true"></div>

  <section id="qr-cookie-banner" class="qr-cookie-banner" role="dialog" aria-live="polite" aria-label="Cookie consent" aria-modal="false" tabindex="-1">
    <div class="qr-cookie-banner__content">
      <h3 class="qr-cookie-title">Мы используем файлы cookie 🍪</h3>
      <p class="qr-cookie-text">
        Мы используем cookie, чтобы сайт работал корректно, запоминал ваши действия в меню и корзине, помогал отслеживать активный заказ, а также улучшал сервис с помощью аналитики и персонализации. Подробнее — в
        <a href="/privacy.php" class="qr-cookie-link">Политике конфиденциальности и Cookies</a>.
      </p>
      <div class="qr-cookie-actions">
        <button type="button" id="qr-cookie-accept-all" class="qr-cookie-btn qr-cookie-btn--primary">Принять все</button>
        <button type="button" id="qr-cookie-necessary-only" class="qr-cookie-btn qr-cookie-btn--secondary">Только необходимые</button>
        <button type="button" id="qr-cookie-open-settings" class="qr-cookie-btn qr-cookie-btn--ghost">Настроить</button>
      </div>
    </div>
  </section>

  <section id="qr-cookie-modal" class="qr-cookie-modal" role="dialog" aria-modal="true" aria-labelledby="qr-cookie-modal-title" tabindex="-1" hidden>
    <div class="qr-cookie-modal__header">
      <h3 id="qr-cookie-modal-title" class="qr-cookie-title">Настройки cookies</h3>
      <button type="button" id="qr-cookie-modal-close" class="qr-cookie-close" aria-label="Закрыть">✕</button>
    </div>
    <p class="qr-cookie-text qr-cookie-text--small">
      Вы можете выбрать, какие категории cookies разрешить. Необходимые cookies всегда активны, потому что без них не работают ключевые функции сайта.
    </p>
    <div class="qr-cookie-categories">
      <article class="qr-cookie-category qr-cookie-category--locked">
        <div>
          <h4>Необходимые</h4>
          <p>используются для работы сайта, меню, корзины, авторизации, оформления заказа, сохранения активного заказа и базовой безопасности.</p>
        </div>
        <span class="qr-cookie-chip">Всегда активны</span>
      </article>
      <label class="qr-cookie-category">
        <div>
          <h4>Аналитические</h4>
          <p>используются для анализа посещаемости и улучшения интерфейса и пользовательского опыта.</p>
        </div>
        <input type="checkbox" id="qr-cookie-analytics">
      </label>
      <label class="qr-cookie-category">
        <div>
          <h4>Персонализация</h4>
          <p>используются для сохранения предпочтений пользователя, состояния меню, языка, активного заказа и связанных удобств.</p>
        </div>
        <input type="checkbox" id="qr-cookie-personalization">
      </label>
      <label class="qr-cookie-category">
        <div>
          <h4>Маркетинговые / сторонние</h4>
          <p>используются для сторонних сервисов, пикселей, рекламных и коммуникационных интеграций, если они будут подключены.</p>
        </div>
        <input type="checkbox" id="qr-cookie-marketing">
      </label>
    </div>
    <div class="qr-cookie-actions qr-cookie-actions--modal">
      <button type="button" id="qr-cookie-save-custom" class="qr-cookie-btn qr-cookie-btn--primary">Сохранить выбор</button>
      <button type="button" id="qr-cookie-accept-all-modal" class="qr-cookie-btn qr-cookie-btn--secondary">Принять все</button>
      <button type="button" id="qr-cookie-necessary-only-modal" class="qr-cookie-btn qr-cookie-btn--ghost">Только необходимые</button>
    </div>
  </section>
</div>

<style>
  .qr-cookie-consent-root{
    --qr-cookie-z-content:2147483000;
    --qr-cookie-z-banner:2147483010;
    --qr-cookie-z-fab:2147483020;
    --qr-cookie-z-backdrop:2147483030;
    --qr-cookie-z-modal:2147483040;
    --qr-cookie-safe-bottom:14px;
    --qr-cookie-banner-gap:16px;
    --qr-cookie-banner-height:0px;
    position:fixed;
    inset:auto 0 0 0;
    z-index:var(--qr-cookie-z-content);
    pointer-events:none
  }
  .qr-cookie-banner,.qr-cookie-modal,.qr-cookie-settings-fab{pointer-events:auto}
  .qr-cookie-banner,.qr-cookie-settings-fab{opacity:0;pointer-events:none;transition:opacity .4s ease}
  .qr-cookie-consent-root.is-visible .qr-cookie-banner,
  .qr-cookie-consent-root.is-visible .qr-cookie-settings-fab{opacity:1}
  .qr-cookie-banner{max-width:960px;margin:0 auto calc(var(--qr-cookie-banner-gap) + env(safe-area-inset-bottom));background:linear-gradient(180deg,rgba(15,23,42,.92),rgba(2,6,23,.92));border:1px solid rgba(148,163,184,.25);border-radius:18px;box-shadow:0 20px 50px rgba(2,6,23,.45);backdrop-filter:blur(10px);padding:16px 18px;z-index:var(--qr-cookie-z-banner)}
  .qr-cookie-title{margin:0 0 8px;font-size:18px;line-height:1.35;color:#f8fafc}
  .qr-cookie-text{margin:0;color:#cbd5e1;font-size:14px;line-height:1.5}
  .qr-cookie-text--small{margin-bottom:14px}
  .qr-cookie-link{color:#6ee7b7;text-decoration:underline}
  .qr-cookie-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
  .qr-cookie-btn{border-radius:12px;padding:10px 14px;font-size:13px;font-weight:600;border:1px solid transparent;cursor:pointer}
  .qr-cookie-btn--primary{background:#34d399;color:#052e16}
  .qr-cookie-btn--primary:hover{background:#6ee7b7}
  .qr-cookie-btn--secondary{background:rgba(15,23,42,.7);border-color:rgba(148,163,184,.35);color:#e2e8f0}
  .qr-cookie-btn--secondary:hover{border-color:rgba(110,231,183,.45)}
  .qr-cookie-btn--ghost{background:transparent;border-color:rgba(148,163,184,.4);color:#cbd5e1}
  .qr-cookie-btn--ghost:hover{background:rgba(15,23,42,.6)}
  .qr-cookie-settings-fab{position:fixed;left:14px;bottom:calc(var(--qr-cookie-safe-bottom) + env(safe-area-inset-bottom));background:rgba(15,23,42,.9);border:1px solid rgba(148,163,184,.35);border-radius:999px;padding:8px 12px;font-size:12px;color:#cbd5e1;backdrop-filter:blur(6px);display:none;z-index:var(--qr-cookie-z-fab)}
  .qr-cookie-settings-fab:hover{border-color:rgba(110,231,183,.55);color:#ecfeff}
  .qr-cookie-backdrop{position:fixed;inset:0;background:rgba(2,6,23,.7);backdrop-filter:blur(3px);z-index:var(--qr-cookie-z-backdrop)}
  .qr-cookie-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(760px,calc(100vw - 24px));max-height:calc(100vh - 36px);overflow:auto;background:linear-gradient(180deg,rgba(15,23,42,.97),rgba(2,6,23,.97));border:1px solid rgba(148,163,184,.28);border-radius:18px;box-shadow:0 24px 55px rgba(2,6,23,.6);padding:16px;z-index:var(--qr-cookie-z-modal)}
  .qr-cookie-modal__header{display:flex;justify-content:space-between;align-items:center;gap:12px}
  .qr-cookie-close{width:34px;height:34px;border-radius:10px;background:rgba(15,23,42,.8);border:1px solid rgba(148,163,184,.4);color:#e2e8f0;cursor:pointer}
  .qr-cookie-categories{display:grid;gap:10px}
  .qr-cookie-category{display:flex;justify-content:space-between;gap:14px;background:rgba(15,23,42,.65);border:1px solid rgba(148,163,184,.25);border-radius:14px;padding:12px}
  .qr-cookie-category h4{margin:0 0 6px;color:#f1f5f9;font-size:14px}
  .qr-cookie-category p{margin:0;color:#cbd5e1;font-size:13px;line-height:1.45}
  .qr-cookie-category input{accent-color:#34d399;inline-size:18px;block-size:18px;margin-top:2px}
  .qr-cookie-chip{display:inline-flex;align-items:center;white-space:nowrap;font-size:12px;color:#a7f3d0;background:rgba(6,78,59,.5);border:1px solid rgba(16,185,129,.4);padding:4px 8px;border-radius:999px;height:max-content}
  .qr-cookie-actions--modal{margin-top:14px;padding-top:8px;border-top:1px solid rgba(148,163,184,.2)}
  body.qr-cookie-banner-open .floating-cart,
  body.qr-cookie-banner-open .qr-floating-cart-shell{
    bottom:calc(var(--qr-cookie-banner-height) + env(safe-area-inset-bottom) + 14px) !important;
  }
  body.qr-cookie-modal-open{overflow:hidden}
  body.qr-cookie-banner-open .admin-fab,
  body.qr-cookie-banner-open .admin-fixed-cta,
  body.qr-cookie-banner-open .restaurant-mobile-nav{
    bottom:calc(var(--qr-cookie-banner-height) + env(safe-area-inset-bottom) + 12px) !important;
  }
  @media (max-width:768px){
    .qr-cookie-banner{margin:0 12px calc(12px + env(safe-area-inset-bottom));padding:14px;border-radius:14px}
    .qr-cookie-title{font-size:16px}
    .qr-cookie-text{font-size:13px}
    .qr-cookie-btn{flex:1 1 calc(50% - 10px);text-align:center}
    .qr-cookie-settings-fab{left:10px}
    body.qr-cookie-banner-open .floating-cart,
    body.qr-cookie-banner-open .qr-floating-cart-shell{
      bottom:calc(var(--qr-cookie-banner-height) + env(safe-area-inset-bottom) + 10px) !important;
    }
  }
</style>

<script>
(function(){
  var STORAGE_PREFIX='qr_cookie_consent_v';
  var STORAGE_VERSION=1;
  var KEY=STORAGE_PREFIX + STORAGE_VERSION;
  var LEGACY_KEYS=['qr_cookie_consent_v1'];
  var root=document.getElementById('qr-cookie-consent-root');
  if(!root){return;}
  root.hidden=false;

  var banner=document.getElementById('qr-cookie-banner');
  var modal=document.getElementById('qr-cookie-modal');
  var backdrop=document.getElementById('qr-cookie-backdrop');
  var fab=document.getElementById('qr-cookie-settings-fab');
  var analytics=document.getElementById('qr-cookie-analytics');
  var personalization=document.getElementById('qr-cookie-personalization');
  var marketing=document.getElementById('qr-cookie-marketing');
  var modalClose=document.getElementById('qr-cookie-modal-close');
  var lastFocusedElement=null;
  var focusableSelector='a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var memoryState=null;
  var storageAvailable=(function(){
    try{
      var probeKey='__qr_cookie_probe__';
      localStorage.setItem(probeKey,'1');
      localStorage.removeItem(probeKey);
      return true;
    }catch(e){
      return false;
    }
  })();

  function defaults(){
    return {version:STORAGE_VERSION,mode:'necessary_only',categories:{analytics:false,personalization:false,marketing:false},updated_at:new Date().toISOString()};
  }
  function normalize(parsed){
    if(!parsed || typeof parsed!=='object'){return null;}
    var mode=typeof parsed.mode==='string' ? parsed.mode : 'necessary_only';
    var cats=(parsed.categories && typeof parsed.categories==='object') ? parsed.categories : {};
    var out={
      version:Number.isFinite(+parsed.version) ? +parsed.version : STORAGE_VERSION,
      mode:mode,
      categories:{
        analytics:!!cats.analytics,
        personalization:!!cats.personalization,
        marketing:!!cats.marketing
      },
      updated_at:typeof parsed.updated_at==='string' ? parsed.updated_at : new Date().toISOString()
    };
    if(['accepted_all','necessary_only','custom_preferences'].indexOf(out.mode)===-1){
      out.mode='custom_preferences';
    }
    return out;
  }
  function parse(raw){
    if(typeof raw!=='string' || raw===''){return null;}
    try{
      return normalize(JSON.parse(raw));
    }catch(e){
      return null;
    }
  }
  function read(){
    if(storageAvailable){
      try{
        var state=parse(localStorage.getItem(KEY));
        if(state){return state;}
        for(var i=0;i<LEGACY_KEYS.length;i++){
          if(LEGACY_KEYS[i]===KEY){continue;}
          state=parse(localStorage.getItem(LEGACY_KEYS[i]));
          if(state){
            write(state, true);
            return state;
          }
        }
        return null;
      }catch(e){
        return memoryState;
      }
    }
    return memoryState;
  }
  function write(state, silent){
    var normalized=normalize(state) || defaults();
    memoryState=normalized;
    if(storageAvailable){
      try{localStorage.setItem(KEY,JSON.stringify(normalized));}catch(e){}
    }
    if(silent===true){return;}
    var nextState=read() || normalized;
    notify(nextState);
    syncUi(nextState);
    closeBanner();
    closeSettings();
    fab.style.display='inline-flex';
  }
  function recomputeOffsets(){
    var floating=document.querySelector('.floating-cart, .qr-floating-cart-shell');
    var safeBottom=14;
    if(floating){
      safeBottom=Math.max(14, Math.round((floating.offsetHeight||0) + 20));
    }
    root.style.setProperty('--qr-cookie-safe-bottom', safeBottom + 'px');
    var height=(banner.offsetHeight||0) + 20;
    root.style.setProperty('--qr-cookie-banner-height', height + 'px');
  }
  function applyBannerState(){
    recomputeOffsets();
    var open=banner.style.display!=='none';
    document.body.classList.toggle('qr-cookie-banner-open', open);
  }
  function preloaderActive(){
    return !!(document.body && document.body.classList.contains('preloader-active') && !document.body.classList.contains('app-loaded'));
  }
  function setCookieVisibility(isVisible){
    root.classList.toggle('is-visible', !!isVisible);
    root.style.opacity=isVisible ? '1' : '0';
    banner.classList.toggle('is-visible', !!isVisible);
    banner.style.opacity=isVisible ? '1' : '0';
    banner.style.pointerEvents=isVisible && banner.style.display!=='none' ? 'auto' : 'none';
    fab.style.opacity=isVisible && fab.style.display!=='none' ? '1' : '0';
    fab.style.pointerEvents=isVisible && fab.style.display!=='none' ? 'auto' : 'none';
  }
  function revealConsentUi(){
    setCookieVisibility(true);
    applyBannerState();
  }
  function focusables(){
    return Array.prototype.slice.call(modal.querySelectorAll(focusableSelector)).filter(function(el){
      return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    });
  }
  function trapFocus(e){
    if(e.key!=='Tab' || modal.hidden){return;}
    var list=focusables();
    if(!list.length){return;}
    var first=list[0];
    var last=list[list.length-1];
    if(e.shiftKey && document.activeElement===first){
      e.preventDefault();
      last.focus();
      return;
    }
    if(!e.shiftKey && document.activeElement===last){
      e.preventDefault();
      first.focus();
    }
  }
  function onDocumentKeydown(e){
    if(e.key==='Escape' && !modal.hidden){
      e.preventDefault();
      closeSettings();
      return;
    }
    if(e.key==='Escape' && banner.style.display!=='none'){
      e.preventDefault();
      acceptNecessaryOnly();
      return;
    }
    trapFocus(e);
  }
  function syncUi(state){
    analytics.checked=!!state.categories.analytics;
    personalization.checked=!!state.categories.personalization;
    marketing.checked=!!state.categories.marketing;
  }
  function notify(state){
    window.dispatchEvent(new CustomEvent('qr:cookie-consent-changed',{detail:state}));
  }
  function openSettings(){
    if(!modal.hidden){return;}
    var state=read() || defaults();
    notify(state);
    syncUi(state);
    lastFocusedElement=document.activeElement;
    modal.hidden=false;
    backdrop.hidden=false;
    fab.setAttribute('aria-expanded','true');
    document.body.classList.add('qr-cookie-modal-open');
    document.addEventListener('keydown', onDocumentKeydown);
    var list=focusables();
    if(list.length){list[0].focus();} else {modal.focus();}
  }
  function closeSettings(){
    if(modal.hidden){return;}
    modal.hidden=true;
    backdrop.hidden=true;
    fab.setAttribute('aria-expanded','false');
    document.body.classList.remove('qr-cookie-modal-open');
    document.removeEventListener('keydown', onDocumentKeydown);
    if(lastFocusedElement && typeof lastFocusedElement.focus==='function'){
      lastFocusedElement.focus();
    } else if(fab.style.display!=='none') {
      fab.focus();
    }
  }
  function closeBanner(){
    banner.style.display='none';
    banner.style.pointerEvents='none';
    applyBannerState();
  }
  function on(el,eventName,handler){
    if(el && typeof el.addEventListener==='function'){
      el.addEventListener(eventName,handler);
    }
  }

  function acceptAll(){
    write({version:STORAGE_VERSION,mode:'accepted_all',categories:{analytics:true,personalization:true,marketing:true},updated_at:new Date().toISOString()});
  }
  function acceptNecessaryOnly(){
    write({version:STORAGE_VERSION,mode:'necessary_only',categories:{analytics:false,personalization:false,marketing:false},updated_at:new Date().toISOString()});
  }
  function saveCustom(){
    write({version:STORAGE_VERSION,mode:'custom_preferences',categories:{analytics:!!analytics.checked,personalization:!!personalization.checked,marketing:!!marketing.checked},updated_at:new Date().toISOString()});
  }

  on(document.getElementById('qr-cookie-accept-all'),'click',acceptAll);
  on(document.getElementById('qr-cookie-necessary-only'),'click',acceptNecessaryOnly);
  on(document.getElementById('qr-cookie-open-settings'),'click',openSettings);
  on(document.getElementById('qr-cookie-save-custom'),'click',saveCustom);
  on(document.getElementById('qr-cookie-accept-all-modal'),'click',acceptAll);
  on(document.getElementById('qr-cookie-necessary-only-modal'),'click',acceptNecessaryOnly);
  on(modalClose,'click',closeSettings);
  on(backdrop,'click',closeSettings);
  on(fab,'click',openSettings);
  window.addEventListener('resize', applyBannerState);
  window.addEventListener('orientationchange', applyBannerState);
  window.addEventListener('qr:preloader-complete', function(){
    revealConsentUi();
  });

  var current=read();
  if(current){
    closeBanner();
    fab.style.display='inline-flex';
    syncUi(current);
    notify(current);
    if(preloaderActive()){
      setCookieVisibility(false);
    }else{
      revealConsentUi();
    }
  }else{
    if(preloaderActive()){
      setCookieVisibility(false);
      window.addEventListener('qr:preloader-complete', function(){
        banner.style.display='block';
        banner.style.pointerEvents='auto';
        revealConsentUi();
      }, {once:true});
    }else{
      banner.style.display='block';
      banner.style.pointerEvents='auto';
      revealConsentUi();
    }
  }

  window.qrCookieConsent={
    getState:function(){return read();},
    hasConsent:function(category){
      if(category==='necessary'){return true;}
      var state=read();
      if(!state){return false;}
      return !!(state.categories && state.categories[category]);
    },
    openSettings:openSettings,
    acceptAll:acceptAll,
    acceptNecessaryOnly:acceptNecessaryOnly,
    saveCustom:saveCustom,
    storageKey:KEY,
    storageVersion:STORAGE_VERSION
  };
})();
</script>
HTML;
    }
}

if (!function_exists('qr_cookie_consent_should_inject')) {
    function qr_cookie_consent_should_inject(string $buffer): bool
    {
        if ($buffer === '' || stripos($buffer, '</body>') === false) {
            return false;
        }
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $value = strtolower(trim(substr($header, strlen('Content-Type:'))));
                if ($value !== '' && strpos($value, 'text/html') === false) {
                    return false;
                }
            }
        }
        return stripos($buffer, 'qr-cookie-consent-root') === false;
    }
}

if (!function_exists('qr_cookie_consent_inject_html')) {
    function qr_cookie_consent_inject_html(string $buffer): string
    {
        if (!qr_cookie_consent_should_inject($buffer)) {
            return $buffer;
        }
        $snippet = qr_cookie_consent_snippet();
        return (string)preg_replace('/<\/body>/i', $snippet . "\n</body>", $buffer, 1);
    }
}
