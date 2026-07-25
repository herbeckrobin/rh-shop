<?php

declare(strict_types=1);

use RhShop\Checkout\Totals;
use RhShop\Orders\Order;

// Enthaltene USt aus dem Brutto herausrechnen (nicht aufschlagen).
eq(Totals::includedTax(11900, Order::TAX_VAT, 19), 1900, '119,00 brutto -> 19,00 USt');
eq(Totals::includedTax(61700, Order::TAX_VAT, 19), 9851, '617,00 brutto -> 98,51 USt');
eq(Totals::includedTax(2490, Order::TAX_VAT, 19), 398, '24,90 brutto -> 3,98 USt');

// Kleinunternehmer: keine USt.
eq(Totals::includedTax(61700, Order::TAX_KLEINUNTERNEHMER, 19), 0, 'Kleinunternehmer keine USt');

// Randfälle: null Betrag, Satz 0.
eq(Totals::includedTax(0, Order::TAX_VAT, 19), 0, 'Betrag 0 keine USt');
eq(Totals::includedTax(-100, Order::TAX_VAT, 19), 0, 'negativer Betrag keine USt');
eq(Totals::includedTax(11900, Order::TAX_VAT, 0), 0, 'Satz 0 keine USt');

// Anderer Satz (z.B. Schweiz 8 %) rechnet korrekt.
eq(Totals::includedTax(10800, Order::TAX_VAT, 8), 800, '108,00 brutto bei 8% -> 8,00 USt');
