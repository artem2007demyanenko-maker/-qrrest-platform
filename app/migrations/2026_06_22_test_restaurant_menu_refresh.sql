-- Test Restaurant menu refresh for non-demo host test.qrrest-menu.ru
-- Idempotent: safe to run multiple times.

SET @test_rest_id := (
    SELECT r.id
    FROM restaurants r
    WHERE r.subdomain = 'test'
    LIMIT 1
);

-- 1) Ensure target categories exist with deterministic sort order.
INSERT INTO menu_categories (restaurant_id, name, sort_order)
SELECT @test_rest_id, c.name, c.sort_order
FROM (
    SELECT 'Завтраки' AS name, 1 AS sort_order
    UNION ALL SELECT 'Закуски', 2
    UNION ALL SELECT 'Салаты', 3
    UNION ALL SELECT 'Супы', 4
    UNION ALL SELECT 'Основные блюда', 5
    UNION ALL SELECT 'Гарниры', 6
    UNION ALL SELECT 'Десерты', 7
    UNION ALL SELECT 'Напитки', 8
    UNION ALL SELECT 'Кофе и чай', 9
) c
WHERE @test_rest_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM menu_categories mc
      WHERE mc.restaurant_id = @test_rest_id
        AND mc.name = c.name
  );

UPDATE menu_categories
SET sort_order = CASE name
    WHEN 'Завтраки' THEN 1
    WHEN 'Закуски' THEN 2
    WHEN 'Салаты' THEN 3
    WHEN 'Супы' THEN 4
    WHEN 'Основные блюда' THEN 5
    WHEN 'Гарниры' THEN 6
    WHEN 'Десерты' THEN 7
    WHEN 'Напитки' THEN 8
    WHEN 'Кофе и чай' THEN 9
    ELSE sort_order
END
WHERE restaurant_id = @test_rest_id
  AND name IN (
      'Завтраки','Закуски','Салаты','Супы','Основные блюда',
      'Гарниры','Десерты','Напитки','Кофе и чай'
  );

-- 2) Re-map existing common demo dish names to the new categories.
UPDATE menu_items
SET category_id = (
    SELECT mc.id
    FROM menu_categories mc
    WHERE mc.restaurant_id = @test_rest_id
      AND mc.name = CASE
          WHEN menu_items.name IN ('Сырники со сметаной','Омлет с томатами и зеленью') THEN 'Завтраки'
          WHEN menu_items.name IN ('Тартар из лосося','Брускетта с рикоттой','Куриные крылья BBQ') THEN 'Закуски'
          WHEN menu_items.name IN ('Салат с креветками и авокадо','Салат «Цезарь» с курицей') THEN 'Салаты'
          WHEN menu_items.name IN ('Суп дня (борщ)','Куриный суп с лапшой') THEN 'Супы'
          WHEN menu_items.name IN ('Стейк рибай 250 г','Филе лосося на гриле','Паста карбонара','Ризотто с белыми грибами','Бургер «Домашний»') THEN 'Основные блюда'
          WHEN menu_items.name IN ('Картофель по-деревенски','Овощи гриль') THEN 'Гарниры'
          WHEN menu_items.name IN ('Чизкейк «Нью-Йорк»','Тирамису') THEN 'Десерты'
          WHEN menu_items.name IN ('Лимонад домашний 0,5 л','Морс клюквенный 0,3 л') THEN 'Напитки'
          WHEN menu_items.name IN ('Эспрессо','Капучино','Чай зелёный жасмин') THEN 'Кофе и чай'
          ELSE NULL
      END
    LIMIT 1
)
WHERE restaurant_id = @test_rest_id
  AND name IN (
      'Сырники со сметаной','Омлет с томатами и зеленью',
      'Тартар из лосося','Брускетта с рикоттой','Куриные крылья BBQ',
      'Салат с креветками и авокадо','Салат «Цезарь» с курицей',
      'Суп дня (борщ)','Куриный суп с лапшой',
      'Стейк рибай 250 г','Филе лосося на гриле','Паста карбонара','Ризотто с белыми грибами','Бургер «Домашний»',
      'Картофель по-деревенски','Овощи гриль',
      'Чизкейк «Нью-Йорк»','Тирамису',
      'Лимонад домашний 0,5 л','Морс клюквенный 0,3 л',
      'Эспрессо','Капучино','Чай зелёный жасмин'
  );

-- 3) Add a few missing dishes so every category is populated.
INSERT INTO menu_items (restaurant_id, category_id, name, description, price, available)
SELECT @test_rest_id, mc.id, t.name, t.description, t.price, 1
FROM (
    SELECT 'Завтраки' AS category_name, 'Сырники со сметаной' AS name, 'Домашние сырники, сметана и ягодный соус.' AS description, 420.00 AS price
    UNION ALL SELECT 'Завтраки', 'Омлет с томатами и зеленью', 'Нежный омлет из трёх яиц с томатами и свежей зеленью.', 360.00
    UNION ALL SELECT 'Салаты', 'Салат «Цезарь» с курицей', 'Романо, куриное филе, пармезан, сухарики, фирменный соус.', 520.00
    UNION ALL SELECT 'Супы', 'Куриный суп с лапшой', 'Прозрачный бульон, куриное филе, домашняя лапша и зелень.', 340.00
    UNION ALL SELECT 'Гарниры', 'Картофель по-деревенски', 'Запечённые дольки картофеля с розмарином и чесноком.', 250.00
    UNION ALL SELECT 'Гарниры', 'Овощи гриль', 'Цукини, баклажан, сладкий перец и томаты на гриле.', 290.00
    UNION ALL SELECT 'Напитки', 'Морс клюквенный 0,3 л', 'Освежающий морс из клюквы с лёгкой кислинкой.', 190.00
    UNION ALL SELECT 'Кофе и чай', 'Чай зелёный жасмин', 'Классический листовой чай с ароматом жасмина.', 220.00
) t
JOIN menu_categories mc
  ON mc.restaurant_id = @test_rest_id
 AND mc.name = t.category_name
WHERE @test_rest_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM menu_items mi
      WHERE mi.restaurant_id = @test_rest_id
        AND mi.name = t.name
  );
