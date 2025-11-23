# XML-page generator for Yandex Market format YML
Плагин для генерации XML со списком товаров для яндекс вебмастера
Поддерживается работа с пользовательскими типами записей и пользовательскими категориями (к примеру, созданными через pods)

Вывод доступен по ссылке: `/api/integrations/yandex/items`
Пример вывода:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="2025-11-23T20:31:31+00:00">
  <shop>
    <name>Название</name>
    <company>Название</company>
    <url>Ссылка на сайт</url>
    <platform>WordPress</platform>
    <categories>
      <category id="4">Название категории</category>
    </categories>
    <offers>
      <offer id="1627">
        <name>Название записи</name>
        <url>Ссылка</url>
        <price>Цена</price>
        <enable_auto_discounts>true</enable_auto_discounts>
        <currencyId>Валюта</currencyId>
        <categoryId>4</categoryId>
        <manufacturer_warranty>true</manufacturer_warranty>
      </offer>
    </offers>
  </shop>
</yml_catalog>
```

## Произвольные поля для товаров:

- _price - цена
- _old_price - старая цена
- _vendor - производитель
- _vendor_code - артикул
- _barcode - штрихкод
- _sales_notes - условия продажи
- _weight - вес
- _dimensions - габариты
- _color - цвет

## Настройки
Страница настроек доступна в Настройки > Яндекс XML
![alt text](https://github.com/vkopaev/wp-yandex-market-xml/blob/main/images/settings.png?raw=true)
