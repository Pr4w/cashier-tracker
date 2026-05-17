# cashier-tracker

Suivi local des paiements Laravel Cashier (Stripe). Enregistre chaque
paiement dans une table locale pour consulter le revenu sans passer par
le dashboard Stripe.

-   Capte les paiements futurs via le webhook Cashier (listener auto-enregistré).
-   Rapatrie l'historique via une commande de backfill (lecture seule côté Stripe).
-   Webhook et backfill partagent la même logique d'écriture, idempotente
    sur `stripe_id` : le backfill est rejouable sans créer de doublon.

Compatibilité : Laravel 11/12, Cashier 15/16, PHP 8.2+.

## Installation

Package non publié sur Packagist. Déclarer le dépôt VCS dans le
`composer.json` du projet hôte :

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/Pr4w/cashier-tracker"
    }
]
```

Puis :

```bash
composer require pr4w/cashier-tracker:^0.1.0 -W
php artisan vendor:publish --tag=cashier-tracker-config
php artisan migrate
```

Le flag `-W` est nécessaire si Cashier est déjà verrouillé dans le
`composer.lock` du projet hôte.

## Configuration

Dans `config/cashier-tracker.php` (ou via `.env`) :

-   `source` : `invoices` (abonnements), `payment_intents` (ventes
    uniques), ou `both` (les deux). En mode `both`, les payment intents
    rattachés à une invoice sont ignorés pour ne pas compter deux fois
    le revenu d'abonnement.
-   `display_currency` : devise affichée (indicatif uniquement, les
    montants sont stockés en centimes).

Variable d'environnement : `CASHIER_TRACKER_SOURCE=invoices`

## Backfill de l'historique

```bash
# Échantillon récent pour valider le mapping avant le run complet
php artisan cashier-tracker:backfill --since=2026-01-01

# Historique complet
php artisan cashier-tracker:backfill
```

Le backfill résout les frais Stripe (`fee`) via la balance
transaction du charge associé : un appel API par paiement, donc lent
sur gros historique. Best-effort : un frais non résolu laisse `fee`
à `null` sans interrompre le backfill.

## Vérification

```bash
php artisan tinker
```

```php
// Total réellement encaissé (hors paiements de test)
\Pr4w\CashierTracker\Models\Payment::live()->sum('amount') / 100;

// Net réel d'une ligne : encaissé - frais - remboursé
\Pr4w\CashierTracker\Models\Payment::first()->net_amount;
```

Comparer le total `live()` au "Gross volume" du dashboard Stripe sur
la même période. Un écart vient en général de devises mélangées (pas
de conversion) ou d'invoices non `paid` (ignorées volontairement).

## Webhook (paiements futurs)

Le listener est auto-enregistré sur `Laravel\Cashier\Events\WebhookReceived`,
rien à câbler côté projet hôte. Il ne se déclenche que si l'endpoint
webhook Cashier est configuré côté Stripe et écoute au minimum
`invoice.payment_succeeded`. Le listener est enveloppé dans un
try/catch : un échec de tracking n'interrompt jamais le traitement
du webhook.

## Modèle

`Pr4w\CashierTracker\Models\Payment`

-   `scopeLive()` : exclut les paiements de test (`livemode = false`)
-   `getNetAmountAttribute()` : `amount - fee - refunded_amount`
-   `getDecimalAmountAttribute()` : montant en unité principale (euros)
-   `scopePaidBetween($from, $to)` : filtre sur `paid_at`

Montants toujours stockés en centimes.
