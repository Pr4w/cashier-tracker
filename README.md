# cashier-tracker

Suivi local des paiements Laravel Cashier (Stripe). Chaque paiement est
enregistré dans une table locale pour consulter le revenu sans passer
par le dashboard Stripe.

-   Capte les paiements futurs via le webhook Cashier (listener
    auto-enregistré, jamais bloquant pour le webhook).
-   Rapatrie l'historique via une commande de backfill (lecture seule
    côté Stripe, rejouable sans doublon).
-   Résout le HT, la TVA, les frais Stripe et le net réel.
-   Rattache chaque paiement au modèle billable (User) via un trait
    optionnel.

Compatibilité : Laravel 11/12, Cashier 15/16, PHP 8.2+.

## Installation

Package privé, non publié sur Packagist. Déclarer le dépôt VCS dans le
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

`-W` est requis si Cashier est déjà verrouillé dans le `composer.lock`
du projet hôte (cas normal).

## Configuration

`config/cashier-tracker.php` :

-   `source` : `invoices` (abonnements, défaut), `payment_intents`
    (ventes uniques), ou `both`. En `both`, les payment intents
    rattachés à une invoice sont ignorés : le revenu d'abonnement n'est
    jamais compté deux fois.
-   `model` : modèle Payment, surchargeable.
-   `display_currency` : devise affichée (indicatif ; montants stockés
    en centimes).
-   `table` : nom de la table.

Override par env : `CASHIER_TRACKER_SOURCE`, `CASHIER_TRACKER_CURRENCY`.

Pour un projet qui ne vend que des abonnements (cas le plus courant),
laisser `invoices`.

## Backfill de l'historique

```bash
# Échantillon récent : valider le mapping avant le run complet
php artisan cashier-tracker:backfill --since=2026-01-01

# Historique complet
php artisan cashier-tracker:backfill
```

Le backfill résout les frais Stripe via le chemin
invoice → payments → payment_intent → charge → balance_transaction
(API Stripe récente). Un appel API par paiement : lent sur gros
historique. Best-effort : un frais non résolu laisse `fee` à `null`
sans interrompre le backfill.

Idempotent sur `stripe_id` : rejouer le backfill met à jour les lignes
existantes sans créer de doublon. Utile pour enrichir rétroactivement
(ex. après ajout du rattachement billable).

## Rattacher les paiements à un utilisateur (optionnel)

Sur le modèle billable (typiquement `App\Models\User`) :

```php
use Pr4w\CashierTracker\Concerns\HasPayments;

class User extends Authenticatable
{
    use Billable;      // Cashier
    use HasPayments;   // ce package
}
```

Méthodes disponibles :

-   `trackedPayments()` : relation morphMany vers les paiements.
-   `totalPaid()` : total encaissé (centimes), hors paiements de test.
-   `netPaid()` : net réel (encaissé - frais - remboursé), hors tests.
-   `paidInvoicesCount()` : nombre de factures payées.

Le rattachement (`billable_type` / `billable_id`) est résolu à
l'écriture via `Cashier::findBillable()`. Un backfill rejoué après
l'ajout du trait remplit l'historique rétroactivement.

## Modèle

`Pr4w\CashierTracker\Models\Payment`

-   `scopeLive()` : exclut les paiements de test (`livemode = false`).
-   `scopePaidBetween($from, $to)` : filtre sur `paid_at`.
-   `net_amount` (accesseur, non stocké) : `amount - fee - refunded_amount`.
-   `decimal_amount` (accesseur) : montant en unité principale.

Montants toujours stockés en centimes. `net_amount` n'est pas une
colonne : c'est une dérivée calculée, non sommable en SQL (utiliser
`->get()->sum(fn ($p) => $p->net_amount)`).

## Webhook (paiements futurs)

Listener auto-enregistré sur `Laravel\Cashier\Events\WebhookReceived`,
rien à câbler. Ne se déclenche que si l'endpoint webhook Cashier est
configuré côté Stripe et écoute au moins `invoice.payment_succeeded`
(et `payment_intent.succeeded` si `source` inclut les ventes uniques).
Enveloppé dans un try/catch : un échec de tracking n'interrompt jamais
le traitement du webhook.

## Vérification

```php
// tinker
\Pr4w\CashierTracker\Models\Payment::live()->sum('amount') / 100;
$p = \Pr4w\CashierTracker\Models\Payment::first();
[$p->amount, $p->fee, $p->net_amount];
```

Comparer le total `live()` au "Gross volume" du dashboard Stripe sur
la même période. Écart fréquent : devises mélangées (pas de
conversion) ou invoices non `paid` (ignorées volontairement).

## Limites connues

-   Pas de conversion de devises : un total brut additionne les montants
    toutes devises confondues.
-   Frais résolus en best-effort : `fee` peut être `null` si la balance
    transaction n'est pas récupérable.
-   `net_amount` calculé en PHP, pas en SQL (non pertinent en SQL).
