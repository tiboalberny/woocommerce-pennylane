# WooCommerce Pennylane Integration

Version: 1.4.0
Développé par Tibo

Plugin WordPress permettant l'intégration entre WooCommerce et Pennylane pour la synchronisation automatique des factures, clients, produits et clients invités.

## État actuel
- ✅ Configuration du plugin
- ✅ Test de connexion API
- ✅ Synchronisation des commandes
- ✅ Synchronisation des clients avec compte
- ✅ Synchronisation des clients invités (sans compte)
- ✅ Synchronisation des produits
- ✅ Gestion des codes comptables

## Fonctionnalités clés
- Synchronisation des commandes WooCommerce vers Pennylane en tant que factures
- Synchronisation des clients WooCommerce vers Pennylane
- Prise en charge des clients invités (sans compte WooCommerce)
- Synchronisation des produits avec leurs caractéristiques (prix, TVA, descriptions)
- Synchronisation automatique ou manuelle selon vos préférences
- Interface d'administration intuitive pour suivre et gérer les synchronisations
- Mode debug pour faciliter le dépannage

## Installation
1. Téléchargez le plugin
2. Installez-le dans WordPress via Extensions > Ajouter
3. Activez-le
4. Configurez vos paramètres Pennylane dans WooCommerce > Pennylane

## Configuration requise
- WordPress 5.8 ou supérieur
- PHP 7.4 ou supérieur
- WooCommerce 5.0 ou supérieur
- Une clé API Pennylane valide

## Synchronisation des clients invités
Le plugin détecte automatiquement les clients qui ont passé commande sans créer de compte WooCommerce, et permet de les synchroniser vers Pennylane sans créer de doublons. Les informations utilisées sont celles fournies lors de la commande.

## Changelog
[Voir CHANGELOG.md](CHANGELOG.md)