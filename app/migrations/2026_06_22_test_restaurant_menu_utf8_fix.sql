-- Fix mojibake for Test Restaurant menu data after wrong-encoding import.
-- Scope: ONLY restaurant with subdomain='test'.
-- Idempotent: can be re-run safely.

SET NAMES utf8mb4;
SET character_set_client = utf8mb4;
SET character_set_connection = utf8mb4;
SET character_set_results = utf8mb4;

SET @test_rest_id := (
    SELECT r.id
    FROM restaurants r
    WHERE r.subdomain = 'test'
    LIMIT 1
);

-- 1) Remove corrupted rows (mojibake) and previously seeded demo rows for this restaurant only.
DELETE mi
FROM menu_items mi
LEFT JOIN menu_categories mc ON mc.id = mi.category_id
WHERE @test_rest_id IS NOT NULL
  AND mi.restaurant_id = @test_rest_id
  AND (
      mi.name LIKE 'Ð%' OR mi.name LIKE 'Ñ%'
      OR COALESCE(mi.description, '') LIKE 'Ð%' OR COALESCE(mi.description, '') LIKE 'Ñ%'
      OR (mc.id IS NOT NULL AND (mc.name LIKE 'Ð%' OR mc.name LIKE 'Ñ%'))
      OR mi.name IN (
          'Сырники со сметаной','Омлет с томатами и зеленью',
          'Тартар из лосося','Брускетта с рикоттой','Куриные крылья BBQ',
          'Салат с креветками и авокадо','Салат «Цезарь» с курицей',
          'Суп дня (борщ)','Куриный суп с лапшой',
          'Стейк рибай 250 г','Филе лосося на гриле','Паста карбонара',
          'Ризотто с белыми грибами','Бургер «Домашний»',
          'Картофель по-деревенски','Овощи гриль',
          'Чизкейк «Нью-Йорк»','Тирамису',
          'Лимонад домашний 0,5 л','Морс клюквенный 0,3 л',
          'Эспрессо','Капучино','Чай зелёный жасмин'
      )
  );

DELETE FROM menu_categories
WHERE @test_rest_id IS NOT NULL
  AND restaurant_id = @test_rest_id
  AND (
      name LIKE 'Ð%' OR name LIKE 'Ñ%'
      OR name IN (
          'Завтраки','Закуски','Салаты','Супы','Основные блюда',
          'Гарниры','Десерты','Напитки','Кофе и чай'
      )
  );

-- 2) Recreate clean categories in correct UTF-8.
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
WHERE @test_rest_id IS NOT NULL;

-- 3) Reinsert demo dish set in proper UTF-8.
INSERT INTO menu_items (restaurant_id, category_id, name, description, price, available)
SELECT @test_rest_id, mc.id, t.name, t.description, t.price, 1
FROM (
    SELECT 'Завтраки' AS category_name, 'Сырники со сметаной' AS name, 'Домашние сырники, сметана и ягодный соус.' AS description, 420.00 AS price
    UNION ALL SELECT 'Завтраки', 'Омлет с томатами и зеленью', 'Нежный омлет из трёх яиц с томатами и свежей зеленью.', 360.00
    UNION ALL SELECT 'Закуски', 'Тартар из лосося', 'Свежий лосось, авокадо, каперсы, лайм, ржаные гренки.', 890.00
    UNION ALL SELECT 'Закуски', 'Брускетта с рикоттой', 'Запечённые томаты, рикотта, базилик, оливковое масло.', 420.00
    UNION ALL SELECT 'Закуски', 'Куриные крылья BBQ', 'Копчёные крылья, соус BBQ, сельдерей, блю-чиз.', 480.00
    UNION ALL SELECT 'Салаты', 'Салат с креветками и авокадо', 'Микс салата, тигровые креветки, авокадо, соус песто.', 650.00
    UNION ALL SELECT 'Салаты', 'Салат «Цезарь» с курицей', 'Романо, куриное филе, пармезан, сухарики, фирменный соус.', 520.00
    UNION ALL SELECT 'Супы', 'Суп дня (борщ)', 'Классический борщ со сметаной и пампушками.', 380.00
    UNION ALL SELECT 'Супы', 'Куриный суп с лапшой', 'Прозрачный бульон, куриное филе, домашняя лапша и зелень.', 340.00
    UNION ALL SELECT 'Основные блюда', 'Стейк рибай 250 г', 'Мраморная говядина, соус из перечного соуса, овощи гриль.', 1890.00
    UNION ALL SELECT 'Основные блюда', 'Филе лосося на гриле', 'Лосось, пюре из цветной капусты, лимонный соус.', 720.00
    UNION ALL SELECT 'Основные блюда', 'Паста карбонара', 'Спагетти, гуанчиале, яичный желток, пармезан.', 590.00
    UNION ALL SELECT 'Основные блюда', 'Ризотто с белыми грибами', 'Карнароли, белые грибы, трюфельное масло.', 640.00
    UNION ALL SELECT 'Основные блюда', 'Бургер «Домашний»', 'Говяжья котлета, чеддер, бекон, маринованные огурцы.', 550.00
    UNION ALL SELECT 'Гарниры', 'Картофель по-деревенски', 'Запечённые дольки картофеля с розмарином и чесноком.', 250.00
    UNION ALL SELECT 'Гарниры', 'Овощи гриль', 'Цукини, баклажан, сладкий перец и томаты на гриле.', 290.00
    UNION ALL SELECT 'Десерты', 'Чизкейк «Нью-Йорк»', 'Классический с ягодным кули.', 420.00
    UNION ALL SELECT 'Десерты', 'Тирамису', 'Маскарпоне, эспрессо, какао.', 390.00
    UNION ALL SELECT 'Напитки', 'Лимонад домашний 0,5 л', 'Мята, лайм, газированная вода.', 220.00
    UNION ALL SELECT 'Напитки', 'Морс клюквенный 0,3 л', 'Освежающий морс из клюквы с лёгкой кислинкой.', 190.00
    UNION ALL SELECT 'Кофе и чай', 'Эспрессо', 'Двойной шот, зёрна из Центральной Америки.', 180.00
    UNION ALL SELECT 'Кофе и чай', 'Капучино', 'Молочная пенка, какао по желанию.', 250.00
    UNION ALL SELECT 'Кофе и чай', 'Чай зелёный жасмин', 'Классический листовой чай с ароматом жасмина.', 220.00
) t
JOIN menu_categories mc
  ON mc.restaurant_id = @test_rest_id
 AND mc.name = t.category_name
WHERE @test_rest_id IS NOT NULL;
