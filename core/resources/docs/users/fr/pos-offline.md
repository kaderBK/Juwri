---
title: Vendre quand Internet est en panne (POS hors ligne)
audience: owner
group: Taking orders
updated: 2026-10-12
---

# Vendre quand Internet est en panne (POS hors ligne)

Votre caisse continue de vendre lorsque Internet ou le serveur est inaccessible, pendant une durée limitée, puis envoie
tout à votre tableau de bord lorsque la connexion revient. Cette page explique ce qui fonctionne encore, ce qui ne
fonctionne pas, et les limites.

## Ce qui fonctionne hors ligne

- **Encaisser les ventes** depuis le menu que votre caisse a téléchargé en dernier : articles, options, variantes, taxes,
  service et les remises que votre personnel est autorisé à accorder.
- **Les paiements en espèces et les paiements manuels** (par exemple un virement bancaire que vous confirmez vous-même),
  avec la monnaie calculée sur la caisse.
- **Les tickets** imprimés depuis la caisse, y compris le code QR lorsque votre pays en exige un.
- **Annuler** une vente faite sur cette caisse, dans ce service, avant qu'elle soit synchronisée (avec un code PIN de
  gestionnaire si votre magasin le demande).
- **Ouvrir et fermer le tiroir-caisse**, les versements et les retraits, et le récapitulatif de service de cette caisse.
- **Les paiements par carte** conservés sur la caisse pour plus tard : uniquement sur une caisse avec l'application de
  lecteur de carte, uniquement dans les limites de carte ci-dessous, et uniquement une fois que votre prestataire de
  paiement a confirmé cette fonction pour votre magasin. D'ici là, les paiements par carte hors ligne sont désactivés et
  la caisse demande un autre moyen de paiement.

Lorsque la connexion revient, la caisse envoie chaque vente, chaque paiement et chaque annulation à votre tableau de bord
dans l'ordre, une seule fois chacun. Une vente n'est jamais créée deux fois, même si la caisse redémarre ou que la
connexion coupe au milieu.

## Limites de temps

| Limite | Par défaut | Maximum |
|---|---|---|
| Durée pendant laquelle une caisse peut vendre sans contacter le serveur | 12 heures | 24 heures |
| Durée pendant laquelle une vente qui ne peut pas être envoyée est conservée sur la caisse | 7 jours | 7 jours |

- Une fois le temps hors ligne écoulé, la caisse refuse les **nouvelles** ventes et affiche « connectez-vous pour
  continuer ». Elle envoie toujours ce qu'elle a et réimprime toujours les tickets. Les heures comptent depuis la dernière
  fois que la caisse a atteint le serveur.
- Si une vente attend encore après 7 jours, la caisse arrête les nouvelles ventes et affiche une alerte au gestionnaire.
  Rien n'est supprimé avant d'avoir été envoyé.
- Les propriétaires de magasin peuvent choisir une durée hors ligne plus courte dans les paramètres du POS ; le maximum
  de la plateforme est de 24 heures.

## Limites de carte

Les paiements par carte hors ligne sont conservés sur la caisse par l'application de lecteur de carte jusqu'à ce que le
prestataire puisse les traiter. La caisse vérifie ces limites **avant** que la carte soit lue :

| Limite | Valeur |
|---|---|
| Un paiement par carte | jusqu'à SAR 250 |
| Tous les paiements par carte non encore envoyés, par caisse | jusqu'à SAR 2,500 |

Vous pouvez fixer des limites plus basses pour votre magasin, pas plus hautes. Au-dessus d'une limite, la caisse affiche
« une connexion est nécessaire » et vous prenez un autre moyen de paiement.

**Le magasin assume le risque.** Un paiement par carte hors ligne ne peut pas être vérifié au moment de la vente. Si la
carte est ensuite refusée, la vente reste dans vos enregistrements et apparaît comme **carte refusée après une vente hors
ligne** sur cette commande, sur le rapport de service et dans la liste **Exceptions hors ligne**, avec le montant,
l'heure, le membre du personnel et la caisse. Juwri ne facture pas à nouveau le client à votre place. Si vous encaissez
l'argent d'une autre manière (espèces, virement), un gestionnaire le marque « encaissé autrement », ce qui ajoute un
paiement correctif et ne supprime jamais le paiement refusé. La caisse et Juwri ne stockent jamais les numéros de carte.

## Les caisses de navigateur sont en espèces uniquement

Une caisse qui fonctionne dans un navigateur web (pas l'application de lecteur de carte) peut vendre hors ligne uniquement
avec **les paiements en espèces et les paiements manuels**. Elle n'a pas d'application de lecteur de carte, donc les
paiements par carte hors ligne n'y sont pas disponibles.

Un navigateur peut aussi effacer ses propres données stockées lorsque le stockage de l'appareil est insuffisant ou que le
navigateur est réinitialisé. Pour protéger vos ventes, la caisse demande au navigateur de conserver ses données et
affiche un avertissement à ce sujet. Si une caisse de navigateur peut rester hors ligne longtemps, envoyez ses ventes dès
que la connexion revient et préférez l'application tablette pour les points de vente fréquentés.

## Ce que votre personnel verra

- Une bannière « Hors ligne » avec le nombre de ventes en attente et le temps hors ligne restant.
- Les prix, les taxes, l'arrondi et les tickets fonctionnent comme en ligne. Si un prix a été modifié sur le tableau de
  bord pendant que la caisse était hors ligne, la vente conserve le prix que le client a vu et payé.
- Après la synchronisation, une courte liste **Exceptions hors ligne** pour le service montre tout ce qu'un gestionnaire
  devrait examiner.

## Ce qui nécessite la connexion (exceptions)

Ces éléments ne fonctionnent pas hors ligne. La caisse vous le dit lorsque vous essayez :

- Les paiements par carte en ligne et les liens de paiement, les paiements par portefeuille ou par solde stocké, les
  cartes cadeaux (vente ou remboursement), l'échange de points de fidélité (les points sont quand même gagnés lorsque la
  vente se synchronise), les coupons qui nécessitent le serveur (codes à usage unique ou propres au client).
- Les remboursements de ventes non effectuées sur cette caisse dans ce service, tout remboursement par carte, et
  l'annulation d'un paiement par carte après qu'il a été envoyé.
- La recherche de clients (un nom et un téléphone saisis sur une vente sont acceptables) et la création de comptes
  clients.
- Les commandes entrantes en ligne et via l'application de livraison, l'attribution des coursiers, l'afficheur cuisine
  sur d'autres appareils, le déplacement ou la fusion d'une table avec une autre caisse, les réservations et la liste
  d'attente.
- Modifier les prix, les articles, les taxes, le personnel ou les paramètres, ouvrir une caisse qui n'a jamais été
  connectée, et connecter un membre du personnel qui ne figurait pas sur la dernière liste téléchargée.
- Les rapports au-delà du récapitulatif de service de cette caisse (le rapport de fin de journée n'est définitif qu'après
  la synchronisation des ventes).
- Les factures fiscales qui nécessitent une autorisation des autorités (par exemple les factures interentreprises en
  Arabie saoudite). Les tickets client simplifiés sont signés sur la caisse elle-même.
- Vendre après l'expiration du temps hors ligne ou de la limite de 7 jours.

## Lorsque deux choses diffèrent

- **Stock :** la caisse peut vendre un article en dessous du stock zéro hors ligne. La vente est acceptée lors de la
  synchronisation et l'article apparaît dans un rapport de **survente** pour vous. Les articles marqués « ne jamais
  survendre » sont bloqués à zéro sur la caisse.
- **La même table sur deux caisses :** les deux ventes sont conservées comme des commandes séparées et signalées.
- **Un service fermé depuis un autre appareil pendant que cette caisse était hors ligne :** les ventes tardives sont
  ajoutées au bon service et son rapport est réédité avec une section « ventes tardives ».

## Une caisse est perdue ou volée

Demandez à la personne qui gère votre compte Juwri de **révoquer** la caisse. La prochaine fois qu'elle atteint
Internet, elle supprime tout ce qu'elle contient, après avoir envoyé les ventes qu'elle peut. Si elle n'atteint jamais
Internet, elle supprime ses données d'elle-même au prochain démarrage une fois qu'elle a été révoquée depuis 3 jours.
Tout ce qui est stocké sur la caisse est chiffré et la clé ne quitte jamais l'appareil.

## Pages connexes

- [Point de vente (POS)](../pos.md) — la caisse, les paiements et le tiroir-caisse.
- [Comptes du personnel](../staff.md) — rôles, codes PIN et autorisations par personne.
- [Gestion des commandes](../orders.md) — où apparaissent les ventes hors ligne synchronisées.