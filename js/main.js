// Базовый JS для стартовой версии.
// Здесь позже появятся:
// - загрузка списка ресторанов из API
// - клиентская логика меню/корзины
// - дополнительные обработчики UI

(function initPlatformHome() {
  if (!window.location.pathname.endsWith('index.html') && window.location.pathname !== '/') {
    return;
  }

  const grid = document.getElementById('restaurants-grid');
  if (!grid) {
    return;
  }

  // TODO: Расширяйте список ресторанов здесь или замените на загрузку через API.
  // Поле `menuUrl` должно указывать на существующий функциональный экран qr-rest
  // (меню/корзина/checkout/fetch/лояльность уже работают там и не изменяются этим экраном).
  const restaurants = [
    {
      id: 'test-restaurant',
      name: 'Тестовый ресторан',
      logo: 'images/logo-placeholder.svg',
      description: 'Онлайн-меню и заказ с доставкой через платформу (без QR у стола).',
      status: 'Открыто меню',
      cta: 'Заказать с доставкой',
      tags: ['Доставка', 'Онлайн-заказ', 'Лояльность'],
      badges: ['Доставка через платформу', 'Рабочий checkout'],
      menuUrl: 'https://test.qrrest-menu.ru/qr.php'
    }
  ];

  grid.innerHTML = restaurants.map(function (restaurant) {
    const tagsHtml = (restaurant.tags || []).map(function (tag) {
      return '<span class="restaurant-tag">' + escapeHtml(tag) + '</span>';
    }).join('');
    const badgesHtml = (restaurant.badges || []).map(function (badge) {
      return '<span class="restaurant-badge">' + escapeHtml(badge) + '</span>';
    }).join('');
    return '' +
      '<article class="restaurant-card">' +
      '  <a class="restaurant-link restaurant-link--platform" href="' + escapeHtml(restaurant.menuUrl) + '" data-restaurant-id="' + escapeHtml(restaurant.id) + '" aria-label="Открыть меню ресторана ' + escapeHtml(restaurant.name) + '">' +
      '    <img class="restaurant-logo" src="' + escapeHtml(restaurant.logo) + '" alt="Логотип ' + escapeHtml(restaurant.name) + '" loading="lazy">' +
      '    <div class="restaurant-content">' +
      '      <div class="restaurant-status-row">' +
      '        <span class="restaurant-status">' + escapeHtml(restaurant.status || 'Доступно') + '</span>' +
      '      </div>' +
      '      <h3>' + escapeHtml(restaurant.name) + '</h3>' +
      '      <p>' + escapeHtml(restaurant.description) + '</p>' +
      '      <div class="restaurant-tags">' + tagsHtml + '</div>' +
      '      <div class="restaurant-badges">' + badgesHtml + '</div>' +
      '      <span class="restaurant-open-link">' + escapeHtml(restaurant.cta || 'Открыть меню') + ' <span aria-hidden="true">→</span></span>' +
      '    </div>' +
      '  </a>' +
      '</article>';
  }).join('');
  grid.classList.add('is-loaded');

  // TODO: Здесь можно подключить router / аналитику переходов по ресторанам.
  grid.addEventListener('click', function (event) {
    const link = event.target.closest('.restaurant-link--platform');
    if (!link) return;
    // Переход выполняется нативно через href, чтобы не ломать текущую маршрутизацию.
  });

  initRevealAnimations();

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function initRevealAnimations() {
    const nodes = document.querySelectorAll('.reveal');
    if (!nodes.length) return;
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.12 });

    nodes.forEach(function (node) {
      observer.observe(node);
    });
  }
})();

(function initRestaurantPageTitle() {
  // Если в URL передано ?name=..., подставляем его в заголовок страницы ресторана.
  if (!window.location.pathname.endsWith('restaurant.html')) {
    return;
  }

  var params = new URLSearchParams(window.location.search);
  var name = params.get('name');
  if (!name) {
    return;
  }

  var titleEl = document.getElementById('restaurant-title');
  if (titleEl) {
    titleEl.textContent = 'Меню ресторана ' + name;
  }
  document.title = 'Меню ресторана ' + name;
})();

(function initRestaurantMenuAndCart() {
  if (!window.location.pathname.endsWith('restaurant.html')) {
    return;
  }

  // Тестовые данные меню, подготовленные на основе:
  // Меню гостя: https://test.qrrest-menu.ru/qr.php (delivery / без table_id)
  // В проде это место заменяется загрузкой JSON из backend API.
  const menuData = [
    { id: 1, category: 'Завтраки', name: 'Сырники со сметаной', description: 'Домашние сырники, сметана и ягодный соус.', price: 420, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 2, category: 'Завтраки', name: 'Омлет с томатами и зеленью', description: 'Нежный омлет из трёх яиц с томатами и свежей зеленью.', price: 360, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 3, category: 'Закуски', name: 'Тартар из лосося', description: 'Свежий лосось, авокадо, каперсы, лайм, ржаные гренки.', price: 890, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 4, category: 'Закуски', name: 'Брускетта с рикоттой', description: 'Запечённые томаты, рикотта, базилик, оливковое масло.', price: 420, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 5, category: 'Закуски', name: 'Куриные крылья BBQ', description: 'Копчёные крылья, соус BBQ, сельдерей, блю-чиз.', price: 480, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 6, category: 'Салаты', name: 'Салат с креветками и авокадо', description: 'Микс салата, тигровые креветки, авокадо, соус песто.', price: 650, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 7, category: 'Салаты', name: 'Салат «Цезарь» с курицей', description: 'Романо, куриное филе, пармезан, сухарики, фирменный соус.', price: 520, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 8, category: 'Супы', name: 'Суп дня (борщ)', description: 'Классический борщ со сметаной и пампушками.', price: 380, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 9, category: 'Супы', name: 'Куриный суп с лапшой', description: 'Прозрачный бульон, куриное филе, домашняя лапша и зелень.', price: 340, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 10, category: 'Основные блюда', name: 'Стейк рибай 250 г', description: 'Мраморная говядина, соус из перечного соуса, овощи гриль.', price: 1890, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 11, category: 'Основные блюда', name: 'Филе лосося на гриле', description: 'Лосось, пюре из цветной капусты, лимонный соус.', price: 720, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 12, category: 'Основные блюда', name: 'Паста карбонара', description: 'Спагетти, гуанчиале, яичный желток, пармезан.', price: 590, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 13, category: 'Основные блюда', name: 'Ризотто с белыми грибами', description: 'Карнароли, белые грибы, трюфельное масло.', price: 640, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 14, category: 'Основные блюда', name: 'Бургер «Домашний»', description: 'Говяжья котлета, чеддер, бекон, маринованные огурцы.', price: 550, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 15, category: 'Гарниры', name: 'Картофель по-деревенски', description: 'Запечённые дольки картофеля с розмарином и чесноком.', price: 250, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 16, category: 'Гарниры', name: 'Овощи гриль', description: 'Цукини, баклажан, сладкий перец и томаты на гриле.', price: 290, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 17, category: 'Десерты', name: 'Чизкейк «Нью-Йорк»', description: 'Классический с ягодным кули.', price: 420, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 18, category: 'Десерты', name: 'Тирамису', description: 'Маскарпоне, эспрессо, какао.', price: 390, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 19, category: 'Напитки', name: 'Berry Juice', description: 'Homemade berry drink', price: 180, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 20, category: 'Напитки', name: 'Лимонад домашний 0,5 л', description: 'Мята, лайм, газированная вода.', price: 220, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 21, category: 'Напитки', name: 'Морс клюквенный 0,3 л', description: 'Освежающий морс из клюквы с лёгкой кислинкой.', price: 190, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 22, category: 'Кофе и чай', name: 'Эспрессо', description: 'Двойной шот, зёрна из Центральной Америки.', price: 180, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 23, category: 'Кофе и чай', name: 'Капучино', description: 'Молочная пенка, какао по желанию.', price: 250, tags: ['Свежая подача', 'КБЖУ'] },
    { id: 24, category: 'Кофе и чай', name: 'Чай зелёный жасмин', description: 'Классический листовой чай с ароматом жасмина.', price: 220, tags: ['Свежая подача', 'КБЖУ'] }
  ];

  const menuContainer = document.getElementById('menu-categories');
  const cartItemsEl = document.getElementById('cart-items');
  const cartTotalEl = document.getElementById('cart-total');
  const clearCartBtn = document.getElementById('clear-cart-btn');
  const checkoutForm = document.getElementById('checkout-form');
  const orderTypeEl = document.getElementById('order-type');
  const guestNameEl = document.getElementById('guest-name');
  const guestPhoneEl = document.getElementById('guest-phone');
  const guestAddressEl = document.getElementById('guest-address');
  const orderCommentsEl = document.getElementById('order-comments');
  const tableIdEl = document.getElementById('table-id');
  const addressWrapEl = document.getElementById('address-field-wrap');
  const tableWrapEl = document.getElementById('table-field-wrap');
  const checkoutErrorEl = document.getElementById('checkout-error');
  const checkoutSuccessEl = document.getElementById('checkout-success');
  const submitOrderBtn = document.getElementById('submit-order-btn');
  const sendErrorWrapEl = document.getElementById('checkout-send-error-wrap');
  const sendErrorTextEl = document.getElementById('checkout-send-error');
  const retryOrderBtn = document.getElementById('retry-order-btn');
  const payloadOutputEl = document.getElementById('payload-output');
  const loyaltyPhoneEl = document.getElementById('loyalty-phone');

  if (!menuContainer || !cartItemsEl || !cartTotalEl || !clearCartBtn || !checkoutForm) {
    return;
  }

  const cart = new Map();
  let isSubmitting = false;
  let lastFailedPayload = null;

  function formatRub(value) {
    return new Intl.NumberFormat('ru-RU').format(value) + ' ₽';
  }

  function groupByCategory(items) {
    return items.reduce(function (acc, item) {
      if (!acc[item.category]) acc[item.category] = [];
      acc[item.category].push(item);
      return acc;
    }, {});
  }

  function renderMenu() {
    const grouped = groupByCategory(menuData);
    menuContainer.innerHTML = '';

    Object.keys(grouped).forEach(function (category) {
      const section = document.createElement('section');
      section.className = 'menu-category';

      const title = document.createElement('h3');
      title.textContent = category;
      section.appendChild(title);

      const grid = document.createElement('div');
      grid.className = 'menu-items-grid';

      grouped[category].forEach(function (dish) {
        const card = document.createElement('article');
        card.className = 'menu-item-card';

        if (Array.isArray(dish.tags) && dish.tags.length > 0) {
          const meta = document.createElement('div');
          meta.className = 'menu-item-meta';
          dish.tags.forEach(function (tag) {
            const badge = document.createElement('span');
            badge.className = 'menu-tag';
            badge.textContent = tag;
            meta.appendChild(badge);
          });
          card.appendChild(meta);
        }

        const dishTitle = document.createElement('h4');
        dishTitle.textContent = dish.name;

        const desc = document.createElement('p');
        desc.className = 'menu-item-desc';
        desc.textContent = dish.description;

        const footer = document.createElement('div');
        footer.className = 'menu-item-footer';

        const price = document.createElement('span');
        price.className = 'menu-item-price';
        price.textContent = formatRub(dish.price);

        const addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'btn btn-primary';
        addBtn.textContent = 'Добавить в корзину';
        addBtn.addEventListener('click', function () {
          addToCart(dish.id);
        });

        const actions = document.createElement('div');
        actions.className = 'menu-item-actions';

        const kbjuBtn = document.createElement('button');
        kbjuBtn.type = 'button';
        kbjuBtn.className = 'btn btn-soft';
        kbjuBtn.textContent = 'КБЖУ';
        kbjuBtn.addEventListener('click', function () {
          alert('Детальные КБЖУ будут доступны после подключения backend справочника.');
        });

        actions.appendChild(kbjuBtn);
        actions.appendChild(addBtn);
        footer.appendChild(price);
        footer.appendChild(actions);

        card.appendChild(dishTitle);
        card.appendChild(desc);
        card.appendChild(footer);
        grid.appendChild(card);
      });

      section.appendChild(grid);
      menuContainer.appendChild(section);
    });
  }

  function addToCart(dishId) {
    const current = cart.get(dishId) || 0;
    cart.set(dishId, current + 1);
    renderCart();
  }

  function updateQty(dishId, nextQty) {
    if (nextQty <= 0) {
      cart.delete(dishId);
    } else {
      cart.set(dishId, nextQty);
    }
    renderCart();
  }

  function renderCart() {
    cartItemsEl.innerHTML = '';

    if (cart.size === 0) {
      const empty = document.createElement('p');
      empty.className = 'cart-empty';
      empty.textContent = 'Добавьте блюда из меню, чтобы оформить заказ.';
      cartItemsEl.appendChild(empty);
      cartTotalEl.textContent = formatRub(0);
      return;
    }

    let total = 0;

    cart.forEach(function (qty, dishId) {
      const dish = menuData.find(function (x) { return x.id === dishId; });
      if (!dish) return;

      const lineTotal = dish.price * qty;
      total += lineTotal;

      const row = document.createElement('div');
      row.className = 'cart-item';

      const header = document.createElement('div');
      header.className = 'cart-item-header';

      const title = document.createElement('span');
      title.className = 'cart-item-title';
      title.textContent = dish.name;

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'remove-btn';
      removeBtn.textContent = 'Удалить';
      removeBtn.addEventListener('click', function () {
        updateQty(dishId, 0);
      });

      header.appendChild(title);
      header.appendChild(removeBtn);

      const controls = document.createElement('div');
      controls.className = 'cart-item-controls';

      const minusBtn = document.createElement('button');
      minusBtn.type = 'button';
      minusBtn.className = 'qty-btn';
      minusBtn.textContent = '−';
      minusBtn.addEventListener('click', function () {
        updateQty(dishId, qty - 1);
      });

      const qtyValue = document.createElement('span');
      qtyValue.className = 'qty-value';
      qtyValue.textContent = String(qty);

      const plusBtn = document.createElement('button');
      plusBtn.type = 'button';
      plusBtn.className = 'qty-btn';
      plusBtn.textContent = '+';
      plusBtn.addEventListener('click', function () {
        updateQty(dishId, qty + 1);
      });

      const sum = document.createElement('span');
      sum.textContent = formatRub(lineTotal);

      controls.appendChild(minusBtn);
      controls.appendChild(qtyValue);
      controls.appendChild(plusBtn);
      controls.appendChild(sum);

      row.appendChild(header);
      row.appendChild(controls);
      cartItemsEl.appendChild(row);
    });

    cartTotalEl.textContent = formatRub(total);
  }

  function getRestaurantName() {
    const titleEl = document.getElementById('restaurant-title');
    const fullTitle = titleEl ? titleEl.textContent.trim() : 'Меню ресторана Тестовый ресторан';
    return fullTitle.replace(/^Меню ресторана\s*/i, '').trim() || 'Тестовый ресторан';
  }

  function getCartTotal() {
    let total = 0;
    cart.forEach(function (qty, dishId) {
      const dish = menuData.find(function (x) { return x.id === dishId; });
      if (!dish) return;
      total += dish.price * qty;
    });
    return total;
  }

  function buildOrderItems() {
    const items = [];
    cart.forEach(function (qty, dishId) {
      const dish = menuData.find(function (x) { return x.id === dishId; });
      if (!dish) return;
      items.push({
        id: String(dish.id),
        name: dish.name,
        category: dish.category,
        quantity: qty,
        price: dish.price
      });
    });
    return items;
  }

  function setError(message) {
    if (!checkoutErrorEl) return;
    if (!message) {
      checkoutErrorEl.textContent = '';
      checkoutErrorEl.classList.add('hidden');
      return;
    }
    checkoutErrorEl.textContent = message;
    checkoutErrorEl.classList.remove('hidden');
  }

  function setSuccess(message) {
    if (!checkoutSuccessEl) return;
    if (!message) {
      checkoutSuccessEl.textContent = '';
      checkoutSuccessEl.classList.add('hidden');
      return;
    }
    checkoutSuccessEl.textContent = message;
    checkoutSuccessEl.classList.remove('hidden');
  }

  function setSendError(message) {
    if (!sendErrorWrapEl || !sendErrorTextEl) return;
    if (!message) {
      sendErrorTextEl.textContent = '';
      sendErrorWrapEl.classList.add('hidden');
      return;
    }
    sendErrorTextEl.textContent = message;
    sendErrorWrapEl.classList.remove('hidden');
  }

  function setLoadingState(loading) {
    isSubmitting = loading;
    if (submitOrderBtn) {
      submitOrderBtn.disabled = loading;
      submitOrderBtn.textContent = loading ? 'Отправка...' : 'Оформить заказ';
    }
    if (retryOrderBtn) {
      retryOrderBtn.disabled = loading;
    }
  }

  function resetCheckoutForm() {
    checkoutForm.reset();
    syncOrderTypeUI();
  }

  async function sendOrder(payload) {
    // TODO: Здесь можно расширить реальный backend flow: auth-токен, идемпотентность, trace-id.
    const response = await fetch('/api/orders/create', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    let body = null;
    try {
      body = await response.json();
    } catch (_) {
      body = null;
    }

    if (!response.ok) {
      const reason = body && (body.error || body.message) ? String(body.error || body.message) : ('HTTP ' + response.status);
      throw new Error(reason);
    }
    return body;
  }

  async function submitPayload(payload) {
    if (isSubmitting) return;
    setLoadingState(true);
    setError('');
    setSuccess('');
    setSendError('');

    // TODO: После успешного ответа backend здесь подключается публикация заказа в KDS.
    // TODO: Здесь же можно вставить шаг оплаты (карта/эквайринг) до финального подтверждения.
    try {
      const result = await sendOrder(payload);
      console.log('Order payload:', payload);
      console.log('Order API response:', result);
      payloadOutputEl.textContent = JSON.stringify(payload, null, 2);

      setSuccess('Заказ успешно оформлен!');
      lastFailedPayload = null;
      cart.clear();
      renderCart();
      resetCheckoutForm();
      alert('Заказ успешно оформлен!');
    } catch (err) {
      console.error('Order submit error:', err);
      lastFailedPayload = payload;
      setSendError('Ошибка при отправке. Попробовать снова?');
    } finally {
      setLoadingState(false);
    }
  }

  function syncOrderTypeUI() {
    const orderType = orderTypeEl.value;
    const isDelivery = orderType === 'delivery';
    const isTable = orderType === 'table';

    addressWrapEl.classList.toggle('hidden', !isDelivery);
    tableWrapEl.classList.toggle('hidden', !isTable);

    guestAddressEl.required = isDelivery;
    tableIdEl.required = isTable;
  }

  function validateCheckout(orderType) {
    const guestName = guestNameEl.value.trim();
    const guestPhone = guestPhoneEl.value.trim();
    const guestAddress = guestAddressEl.value.trim();
    const tableId = tableIdEl.value.trim();

    if (cart.size === 0) {
      return 'Корзина пустая. Добавьте блюда перед оформлением.';
    }
    if (!guestName) {
      return 'Укажите имя гостя.';
    }
    if (!guestPhone) {
      return 'Укажите телефон.';
    }
    if (orderType === 'delivery' && !guestAddress) {
      return 'Для доставки укажите адрес.';
    }
    if (orderType === 'table' && !tableId) {
      return 'Для заказа через стол укажите номер стола.';
    }
    return '';
  }

  clearCartBtn.addEventListener('click', function () {
    cart.clear();
    renderCart();
  });

  orderTypeEl.addEventListener('change', function () {
    syncOrderTypeUI();
  });

  if (loyaltyPhoneEl && guestPhoneEl) {
    loyaltyPhoneEl.addEventListener('input', function () {
      // Синхронизируем телефон лояльности с checkout для единообразия и будущего loyalty flow.
      guestPhoneEl.value = loyaltyPhoneEl.value;
    });
    guestPhoneEl.addEventListener('input', function () {
      if (!loyaltyPhoneEl.value.trim()) {
        loyaltyPhoneEl.value = guestPhoneEl.value;
      }
    });
  }

  checkoutForm.addEventListener('submit', function (event) {
    event.preventDefault();
    setError('');
    setSuccess('');
    setSendError('');

    const orderType = orderTypeEl.value;
    const validationError = validateCheckout(orderType);
    if (validationError) {
      setError(validationError);
      return;
    }

    const guestAddress = guestAddressEl.value.trim();
    const tableId = tableIdEl.value.trim();
    const payload = {
      restaurant: getRestaurantName(),
      table_id: orderType === 'table' ? tableId : null,
      guest: {
        name: guestNameEl.value.trim(),
        phone: (loyaltyPhoneEl && loyaltyPhoneEl.value.trim()) ? loyaltyPhoneEl.value.trim() : guestPhoneEl.value.trim(),
        address: orderType === 'delivery' ? guestAddress : ''
      },
      order_items: buildOrderItems(),
      total: getCartTotal(),
      comments: orderCommentsEl.value.trim()
    };

    submitPayload(payload);
  });

  if (retryOrderBtn) {
    retryOrderBtn.addEventListener('click', function () {
      if (!lastFailedPayload) {
        setSendError('Нет сохраненного payload для повторной отправки.');
        return;
      }
      submitPayload(lastFailedPayload);
    });
  }

  renderMenu();
  renderCart();
  syncOrderTypeUI();
})();
