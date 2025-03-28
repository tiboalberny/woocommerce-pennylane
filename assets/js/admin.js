jQuery(document).ready(function($) {
    console.log("WooPennylane: JavaScript chargé");
    
    // Test de la connexion API
    $('#woo-pennylane-test-connection').on('click', function(e) {
        e.preventDefault();
        
        console.log("Bouton de test de connexion cliqué");
        
        const button = $(this);
        const apiKey = $('#woo_pennylane_api_key').val();
        
        button.prop('disabled', true);
        button.after('<span class="spinner is-active"></span>');

        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_test_connection',
                nonce: wooPennylaneParams.nonce,
                api_key: apiKey
            },
            success: function(response) {
                button.next('.spinner').remove();
                
                if (response.success) {
                    button.after('<div class="notice notice-success inline"><p>' + response.data + '</p></div>');
                } else {
                    button.after('<div class="notice notice-error inline"><p>' + response.data + '</p></div>');
                }
                
                setTimeout(function() {
                    button.next('.notice').fadeOut(function() {
                        $(this).remove();
                    });
                }, 3000);
            },
            error: function(xhr, status, error) {
                console.error("Erreur AJAX:", status, error);
                button.next('.spinner').remove();
                button.after('<div class="notice notice-error inline"><p>Erreur de communication avec le serveur</p></div>');
                
                setTimeout(function() {
                    button.next('.notice').fadeOut(function() {
                        $(this).remove();
                    });
                }, 3000);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Toggle visibilité du champ API key
    // Modification du code pour éviter la duplication du bouton
    // NE PAS ajouter de bouton supplémentaire, utiliser seulement celui qui existe déjà
    $('.api-key-wrapper input[type="password"]').each(function() {
        const input = $(this);
        // Création du bouton si nécessaire (si aucun bouton de toggle n'existe déjà)
        if (input.next('.toggle-password').length === 0) {
            const showButton = $('<button type="button" class="button button-secondary toggle-password">Afficher</button>');
            input.after(showButton);
            
            showButton.on('click', function(e) {
                e.preventDefault();
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    showButton.text('Masquer');
                } else {
                    input.attr('type', 'password');
                    showButton.text('Afficher');
                }
            });
        }
    });

   // Gestion de la synchronisation des clients depuis la liste des utilisateurs
    $(document).on('click', '.sync-pennylane-customer', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const userId = button.data('user-id');
        const nonce = button.data('nonce');
        const spinner = button.next('.spinner');
        const resultSpan = button.siblings('.sync-result');
        const isResync = button.hasClass('resync');
        
        // Désactiver le bouton et afficher le spinner
        button.prop('disabled', true);
        spinner.addClass('is-active');
        resultSpan.empty();
        
        // Envoyer la requête AJAX
        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_sync_single_customer',
                nonce: wooPennylaneParams.nonce,
                customer_id: userId,
                user_nonce: nonce,
                force_resync: isResync ? 'yes' : 'no'
            },
            success: function(response) {
                spinner.removeClass('is-active');
                
                if (response.success) {
                    resultSpan.html('<span class="sync-success" style="color:green;">' + response.data.message + '</span>');
                    
                    // Rafraîchir la page après un court délai
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    resultSpan.html('<span class="sync-error" style="color:red;">' + response.data.message + '</span>');
                }
            },
            error: function() {
                spinner.removeClass('is-active');
                resultSpan.html('<span class="sync-error" style="color:red;">' + wooPennylaneParams.i18n.sync_error + '</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
            
    // Analyse des commandes
    $('#analyze-orders').on('click', function(e) {
        e.preventDefault();
        
        console.log("Bouton d'analyse des commandes cliqué");
        
        const button = $(this);
        const dateStart = $('#sync-date-start').val();
        const dateEnd = $('#sync-date-end').val();
        
        if (!dateStart || !dateEnd) {
            alert('Veuillez sélectionner une période');
            return;
        }

        button.prop('disabled', true);
        button.after('<span class="spinner is-active"></span>');

        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_analyze_orders',
                nonce: wooPennylaneParams.nonce,
                date_start: dateStart,
                date_end: dateEnd
            },
            success: function(response) {
                console.log("Réponse d'analyse des commandes:", response);
                button.next('.spinner').remove();
                
                if (response.success) {
                    const data = response.data;
                    
                    $('#orders-found').text(data.total);
                    $('#orders-synced').text(data.synced);
                    $('#orders-to-sync').text(data.to_sync);
                    
                    $('#sync-results').show();
                    $('#start-sync').prop('disabled', data.to_sync === 0);
                } else {
                    alert(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error("Erreur AJAX:", status, error);
                button.next('.spinner').remove();
                alert('Erreur de communication avec le serveur');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Synchronisation des commandes
    $('#start-sync').on('click', function(e) {
        e.preventDefault();
        
        console.log("Bouton de synchronisation des commandes cliqué");
        
        const button = $(this);
        const dateStart = $('#sync-date-start').val();
        const dateEnd = $('#sync-date-end').val();
        
        button.prop('disabled', true);
        $('#sync-progress').show();
        
        let processedOrders = 0;
        const totalOrders = parseInt($('#orders-to-sync').text(), 10);

        function updateProgress(current, total) {
            const percentage = Math.round((current / total) * 100);
            $('.progress-bar-inner').css('width', percentage + '%');
            $('.progress-text').text(percentage + '%');
        }

        function addLogEntry(message, type) {
            const entry = $('<div class="log-entry ' + type + '">' + message + '</div>');
            $('#sync-log-content').prepend(entry);
        }

        function syncNextBatch() {
            $.ajax({
                url: wooPennylaneParams.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'woo_pennylane_sync_orders',
                    nonce: wooPennylaneParams.nonce,
                    date_start: dateStart,
                    date_end: dateEnd,
                    offset: processedOrders
                },
                success: function(response) {
                    console.log("Réponse de synchronisation:", response);
                    if (response.success) {
                        processedOrders += response.data.processed;
                        
                        response.data.results.forEach(function(result) {
                            addLogEntry(result.message, result.status);
                        });
                        
                        updateProgress(processedOrders, totalOrders);
                        
                        if (processedOrders < totalOrders) {
                            syncNextBatch();
                        } else {
                            button.prop('disabled', false);
                            addLogEntry('Synchronisation terminée', 'success');
                        }
                    } else {
                        button.prop('disabled', false);
                        addLogEntry('Erreur : ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Erreur AJAX:", status, error);
                    button.prop('disabled', false);
                    addLogEntry('Erreur de communication avec le serveur', 'error');
                }
            });
        }

        // Démarrer la synchronisation
        syncNextBatch();
    });
    
    // Analyse des clients
    $('#analyze-customers').on('click', function(e) {
        e.preventDefault();
        
        console.log("Bouton d'analyse des clients cliqué");
        
        const button = $(this);
        
        button.prop('disabled', true);
        button.after('<span class="spinner is-active"></span>');

        console.log("Envoi de la requête AJAX:", {
            url: wooPennylaneParams.ajaxUrl,
            nonce: wooPennylaneParams.nonce,
            action: 'woo_pennylane_analyze_customers'
        });

        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_analyze_customers',
                nonce: wooPennylaneParams.nonce
            },
            success: function(response) {
                console.log("Réponse d'analyse des clients:", response);
                button.next('.spinner').remove();
                
                if (response.success) {
                    const data = response.data;
                    
                    $('#customers-found').text(data.total);
                    $('#customers-synced').text(data.synced);
                    $('#customers-to-sync').text(data.to_sync);
                    
                    $('#customers-results').show();
                    $('#start-customer-sync').prop('disabled', data.to_sync === 0);
                } else {
                    alert(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error("Erreur AJAX:", status, error);
                button.next('.spinner').remove();
                alert('Erreur de communication avec le serveur');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Synchronisation des clients
    $('#start-customer-sync').on('click', function(e) {
        e.preventDefault();
        
        console.log("Bouton de synchronisation des clients cliqué");
        
        const button = $(this);
        
        button.prop('disabled', true);
        $('#customer-sync-progress').show();
        
        let processedCustomers = 0;
        const totalCustomers = parseInt($('#customers-to-sync').text(), 10);

        function updateCustomerProgress(current, total) {
            const percentage = Math.round((current / total) * 100);
            $('#customer-sync-progress .progress-bar-inner').css('width', percentage + '%');
            $('#customer-sync-progress .progress-text').text(percentage + '%');
        }

        function addCustomerLogEntry(message, type) {
            const entry = $('<div class="log-entry ' + type + '">' + message + '</div>');
            $('#customer-sync-log-content').prepend(entry);
        }

        function syncNextCustomerBatch() {
            $.ajax({
                url: wooPennylaneParams.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'woo_pennylane_sync_customers',
                    nonce: wooPennylaneParams.nonce,
                    offset: processedCustomers
                },
                success: function(response) {
                    console.log("Réponse de synchronisation des clients:", response);
                    if (response.success) {
                        processedCustomers += response.data.processed;
                        
                        response.data.results.forEach(function(result) {
                            addCustomerLogEntry(result.message, result.status);
                        });
                        
                        updateCustomerProgress(processedCustomers, totalCustomers);
                        
                        if (processedCustomers < totalCustomers) {
                            syncNextCustomerBatch();
                        } else {
                            button.prop('disabled', false);
                            addCustomerLogEntry('Synchronisation des clients terminée', 'success');
                        }
                    } else {
                        button.prop('disabled', false);
                        addCustomerLogEntry('Erreur : ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Erreur AJAX:", status, error);
                    button.prop('disabled', false);
                    addCustomerLogEntry('Erreur de communication avec le serveur', 'error');
                }
            });
        }

        // Démarrer la synchronisation
        syncNextCustomerBatch();
    });


        // Gestion du bouton de resynchronisation forcée
        $('#force-resync-customers').on('click', function(e) {
            e.preventDefault();
            $("#resync-confirmation-dialog").dialog("open");
        });
        
        // Fonction pour forcer la resynchronisation
        function forceResyncCustomers() {
            const button = $('#force-resync-customers');
            
            button.prop('disabled', true);
            button.after('<span class="spinner is-active"></span>');
            
            // Affiche la barre de progression
            $('#customer-sync-progress').show();
            $('#customer-sync-log-content').empty();
            
            // Ajout d'un message de début
            addCustomerLogEntry(__('Début de la resynchronisation forcée des clients...', 'woo-pennylane'), 'info');
            
            // Appel AJAX pour récupérer la liste des clients à resynchroniser
            $.ajax({
                url: wooPennylaneParams.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'woo_pennylane_get_synced_customers',
                    nonce: wooPennylaneParams.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const customers = response.data.customers;
                        const totalCustomers = customers.length;
                        
                        if (totalCustomers === 0) {
                            addCustomerLogEntry(__('Aucun client à resynchroniser.', 'woo-pennylane'), 'info');
                            button.prop('disabled', false);
                            button.next('.spinner').remove();
                            return;
                        }
                        
                        addCustomerLogEntry(
                            sprintf(__('%d clients trouvés pour la resynchronisation.', 'woo-pennylane'), totalCustomers),
                            'info'
                        );
                        
                        // Initialisation des compteurs
                        let processedCount = 0;
                        let successCount = 0;
                        let errorCount = 0;
                        
                        // Fonction de mise à jour de la progression
                        function updateResyncProgress() {
                            const percentage = Math.round((processedCount / totalCustomers) * 100);
                            $('#customer-sync-progress .progress-bar-inner').css('width', percentage + '%');
                            $('#customer-sync-progress .progress-text').text(percentage + '%');
                        }
                        
                        // Fonction récursive pour synchroniser les clients un par un
                        function resyncNextCustomer(index) {
                            if (index >= totalCustomers) {
                                // Fin de la resynchronisation
                                addCustomerLogEntry(
                                    sprintf(__('Resynchronisation terminée. %d clients synchronisés, %d erreurs.', 'woo-pennylane'), 
                                        successCount, errorCount),
                                    'success'
                                );
                                button.prop('disabled', false);
                                button.next('.spinner').remove();
                                return;
                            }
                            
                            const customerId = customers[index];
                            
                            $.ajax({
                                url: wooPennylaneParams.ajaxUrl,
                                type: 'POST',
                                data: {
                                    action: 'woo_pennylane_sync_single_customer',
                                    nonce: wooPennylaneParams.nonce,
                                    customer_id: customerId,
                                    force_resync: 'yes'
                                },
                                success: function(response) {
                                    processedCount++;
                                    
                                    if (response.success) {
                                        successCount++;
                                        addCustomerLogEntry(response.data.message, 'success');
                                    } else {
                                        errorCount++;
                                        addCustomerLogEntry(
                                            sprintf(__('Erreur client #%d: %s', 'woo-pennylane'), 
                                                customerId, response.data.message),
                                            'error'
                                        );
                                    }
                                    
                                    updateResyncProgress();
                                    
                                    // Traitement du client suivant
                                    resyncNextCustomer(index + 1);
                                },
                                error: function() {
                                    processedCount++;
                                    errorCount++;
                                    
                                    addCustomerLogEntry(
                                        sprintf(__('Erreur de communication lors de la synchronisation du client #%d', 'woo-pennylane'), 
                                            customerId),
                                        'error'
                                    );
                                    
                                    updateResyncProgress();
                                    
                                    // Continue avec le client suivant malgré l'erreur
                                    resyncNextCustomer(index + 1);
                                }
                            });
                        }
                        
                        // Démarrer la resynchronisation avec le premier client
                        resyncNextCustomer(0);
                        
                    } else {
                        addCustomerLogEntry(__('Erreur: ') + response.data, 'error');
                        button.prop('disabled', false);
                        button.next('.spinner').remove();
                    }
                },
                error: function() {
                    addCustomerLogEntry(__('Erreur de communication avec le serveur', 'woo-pennylane'), 'error');
                    button.prop('disabled', false);
                    button.next('.spinner').remove();
                }
            });
        }

        // Fonction helper pour ajouter des entrées dans le journal
        function addCustomerLogEntry(message, type) {
            const entry = $('<div class="log-entry ' + type + '">' + message + '</div>');
            $('#customer-sync-log-content').prepend(entry);
        }

    // Analyse des produits
    $('#analyze-products').on('click', function(e) {
        e.preventDefault();
        
        console.log("Bouton d'analyse des produits cliqué");
        
        const button = $(this);
        
        button.prop('disabled', true);
        button.after('<span class="spinner is-active"></span>');

        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_analyze_products',
                nonce: wooPennylaneParams.nonce
            },
            success: function(response) {
                console.log("Réponse d'analyse des produits:", response);
                button.next('.spinner').remove();
                
                if (response.success) {
                    const data = response.data;
                    
                    $('#products-found').text(data.total);
                    $('#products-synced').text(data.synced);
                    $('#products-to-sync').text(data.to_sync);
                    
                    $('#products-results').show();
                    $('#start-product-sync').prop('disabled', data.to_sync === 0);
                } else {
                    alert(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error("Erreur AJAX:", status, error);
                button.next('.spinner').remove();
                alert('Erreur de communication avec le serveur');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Synchronisation des produits
    $('#start-product-sync').on('click', function(e) {
        e.preventDefault();
        
        console.log("Bouton de synchronisation des produits cliqué");
        
        const button = $(this);
        
        button.prop('disabled', true);
        $('#product-sync-progress').show();
        
        let processedProducts = 0;
        const totalProducts = parseInt($('#products-to-sync').text(), 10);

        function updateProductProgress(current, total) {
            const percentage = Math.round((current / total) * 100);
            $('#product-sync-progress .progress-bar-inner').css('width', percentage + '%');
            $('#product-sync-progress .progress-text').text(percentage + '%');
        }

        function addProductLogEntry(message, type) {
            const entry = $('<div class="log-entry ' + type + '">' + message + '</div>');
            $('#product-sync-log-content').prepend(entry);
        }

        function syncNextProductBatch() {
            $.ajax({
                url: wooPennylaneParams.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'woo_pennylane_sync_products',
                    nonce: wooPennylaneParams.nonce,
                    offset: processedProducts
                },
                success: function(response) {
                    console.log("Réponse de synchronisation des produits:", response);
                    if (response.success) {
                        processedProducts += response.data.processed;
                        
                        response.data.results.forEach(function(result) {
                            addProductLogEntry(result.message, result.status);
                        });
                        
                        updateProductProgress(processedProducts, totalProducts);
                        
                        if (processedProducts < totalProducts) {
                            syncNextProductBatch();
                        } else {
                            button.prop('disabled', false);
                            addProductLogEntry('Synchronisation des produits terminée', 'success');
                        }
                    } else {
                        button.prop('disabled', false);
                        addProductLogEntry('Erreur : ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error("Erreur AJAX:", status, error);
                    button.prop('disabled', false);
                    addProductLogEntry('Erreur de communication avec le serveur', 'error');
                }
            });
        }

        // Démarrer la synchronisation
        syncNextProductBatch();
    });
/**
 * Gestion de la synchronisation des produits avec Pennylane
 */
jQuery(document).ready(function($) {
    // Synchronisation d'un produit individuel (depuis la liste ou la métabox)
    $(document).on('click', '.pennylane-sync-product', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const productId = button.data('product-id');
        
        if (!productId) {
            return;
        }
        
        button.prop('disabled', true);
        
        // Gérer le spinner différemment selon le contexte (liste de produits ou métabox)
        let spinner;
        if (button.closest('.pennylane-actions').length) {
            // Dans la liste des produits
            spinner = button.closest('.pennylane-actions').find('.spinner');
        } else {
            // Dans la métabox (page d'édition du produit)
            if (button.next('.spinner').length === 0) {
                button.after('<span class="spinner is-active"></span>');
            }
            spinner = button.next('.spinner');
        }
        
        spinner.addClass('is-active');
        
        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_sync_single_product',
                nonce: wooPennylaneParams.nonce,
                product_id: productId
            },
            success: function(response) {
                spinner.removeClass('is-active');
                
                if (response.success) {
                    handleSuccessfulSync(button, response.data);
                } else {
                    handleFailedSync(button, response.data);
                }
            },
            error: function(xhr, status, error) {
                spinner.removeClass('is-active');
                
                const errorMessage = 'Erreur de communication avec le serveur: ' + status;
                console.error('Pennylane sync error:', error, xhr.responseText);
                
                // Afficher l'erreur dans l'interface
                if (button.closest('.pennylane-product-sync-box').length) {
                    showNotice(button.closest('.pennylane-product-sync-box'), errorMessage, 'error');
                } else {
                    button.closest('.pennylane-column-content').append(
                        '<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>'
                    );
                }
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
    
    /**
     * Gère l'affichage après une synchronisation réussie
     */
    function handleSuccessfulSync(button, data) {
        // Si nous sommes dans la métabox
        if (button.closest('.pennylane-product-sync-box').length) {
            const container = button.closest('.pennylane-product-sync-box');
            
            // Mise à jour des informations de synchronisation
            container.find('.pennylane-not-synced')
                .removeClass('pennylane-not-synced')
                .addClass('pennylane-synced')
                .text(wooPennylaneParams.i18n.synced);
            
            container.find('.pennylane-sync-error').remove();
            
            // Mise à jour ou ajout de l'ID Pennylane
            if (container.find('p:contains("' + wooPennylaneParams.i18n.pennylane_id + '")').length === 0) {
                container.find('p:first').after('<p>' + wooPennylaneParams.i18n.pennylane_id + ' <strong>' + data.pennylane_id + '</strong></p>');
            } else {
                container.find('p:contains("' + wooPennylaneParams.i18n.pennylane_id + '") strong').text(data.pennylane_id);
            }
            
            // Mise à jour ou ajout de la date de synchronisation
            if (container.find('p:contains("' + wooPennylaneParams.i18n.last_synced + '")').length === 0) {
                container.append('<p>' + wooPennylaneParams.i18n.last_synced + ' <span>' + data.human_time + '</span></p>');
            } else {
                container.find('p:contains("' + wooPennylaneParams.i18n.last_synced + '") span').text(data.human_time);
            }
            
            // Message de succès
            showNotice(container, data.message, 'success');
        } 
        // Si nous sommes dans la liste des produits
        else {
            const columnContent = button.closest('.pennylane-column-content');
            
            // Mise à jour du statut
            columnContent.find('.pennylane-status')
                .removeClass('not-synced error')
                .addClass('synced')
                .html('<span class="dashicons dashicons-yes"></span> ' + 
                      wooPennylaneParams.i18n.synced + 
                      ' <span class="sync-time" title="' + data.last_sync + '">(' + 
                      data.human_time + ')</span>');
            
            // Notification de succès temporaire
            const notification = $('<div class="notice notice-success inline"><p>' + data.message + '</p></div>');
            columnContent.append(notification);
            
            setTimeout(function() {
                notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 3000);
        }
    }
    
    /**
     * Gère l'affichage après une synchronisation échouée
     */
    function handleFailedSync(button, data) {
        const errorMessage = data.message || 'Erreur de synchronisation';
        
        if (button.closest('.pennylane-product-sync-box').length) {
            // Dans la métabox
            const container = button.closest('.pennylane-product-sync-box');
            showNotice(container, errorMessage, 'error');
        } else {
            // Dans la liste des produits
            const columnContent = button.closest('.pennylane-column-content');
            
            // Mise à jour du statut en cas d'erreur
            columnContent.find('.pennylane-status')
                .removeClass('synced not-synced')
                .addClass('error')
                .html('<span class="dashicons dashicons-warning"></span> ' + 
                       wooPennylaneParams.i18n.sync_error + 
                       '<span class="error-details" title="' + errorMessage + 
                       '"><span class="dashicons dashicons-info"></span></span>');
            
            // Notification d'erreur temporaire
            const notification = $('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
            columnContent.append(notification);
            
            setTimeout(function() {
                notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    }
    
    /**
     * Affiche une notification dans un conteneur
     */
    function showNotice(container, message, type) {
        // Supprimer les notifications existantes
        container.find('.notice').remove();
        
        // Ajouter la nouvelle notification
        const notice = $('<div class="notice notice-' + type + ' inline"><p>' + message + '</p></div>');
        container.append(notice);
        
        // Faire disparaître la notification après un délai
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, type === 'error' ? 5000 : 3000);
    }
});
jQuery(document).ready(function($) {
    console.log("Test JavaScript WooPennylane");
    
    // Test spécifique pour le bouton
    if ($('.sync-pennylane-customer').length) {
        console.log("Boutons trouvés:", $('.sync-pennylane-customer').length);
    } else {
        console.log("Aucun bouton n'a été trouvé avec la classe .sync-pennylane-customer");
    }

    // Analyse des clients invités
$('#analyze-guest-customers').on('click', function(e) {
    e.preventDefault();
    
    console.log("Bouton d'analyse des clients invités cliqué");
    
    const button = $(this);
    
    button.prop('disabled', true);
    button.after('<span class="spinner is-active"></span>');

    $.ajax({
        url: wooPennylaneParams.ajaxUrl,
        type: 'POST',
        data: {
            action: 'woo_pennylane_analyze_guest_customers',
            nonce: wooPennylaneParams.nonce
        },
        success: function(response) {
            console.log("Réponse d'analyse des clients invités:", response);
            button.next('.spinner').remove();
            
            if (response.success) {
                const data = response.data;
                
                $('#guest-customers-found').text(data.total);
                $('#guest-customers-synced').text(data.synced);
                $('#guest-customers-to-sync').text(data.to_sync);
                
                $('#guest-customers-results').show();
                $('#start-guest-customer-sync').prop('disabled', data.to_sync === 0);
            } else {
                alert(response.data);
            }
        },
        error: function(xhr, status, error) {
            console.error("Erreur AJAX:", status, error);
            button.next('.spinner').remove();
            alert('Erreur de communication avec le serveur');
        },
        complete: function() {
            button.prop('disabled', false);
        }
    });
});

// Synchronisation des clients invités
$('#start-guest-customer-sync').on('click', function(e) {
    e.preventDefault();
    
    console.log("Bouton de synchronisation des clients invités cliqué");
    
    const button = $(this);
    
    button.prop('disabled', true);
    $('#guest-customer-sync-progress').show();
    
    let processedCustomers = 0;
    const totalCustomers = parseInt($('#guest-customers-to-sync').text(), 10);

    function updateProgress(current, total) {
        const percentage = Math.round((current / total) * 100);
        $('#guest-customer-sync-progress .progress-bar-inner').css('width', percentage + '%');
        $('#guest-customer-sync-progress .progress-text').text(percentage + '%');
    }

    function addLogEntry(message, type) {
        const entry = $('<div class="log-entry ' + type + '">' + message + '</div>');
        $('#guest-customer-sync-log-content').prepend(entry);
    }

    function syncNextBatch() {
        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_sync_guest_customers',
                nonce: wooPennylaneParams.nonce,
                offset: processedCustomers
            },
            success: function(response) {
                console.log("Réponse de synchronisation des clients invités:", response);
                if (response.success) {
                    processedCustomers += response.data.processed;
                    
                    response.data.results.forEach(function(result) {
                        addLogEntry(result.message, result.status);
                    });
                    
                    updateProgress(processedCustomers, totalCustomers);
                    
                    if (processedCustomers < totalCustomers) {
                        syncNextBatch();
                    } else {
                        button.prop('disabled', false);
                        addLogEntry('Synchronisation des clients invités terminée', 'success');
                    }
                } else {
                    button.prop('disabled', false);
                    addLogEntry('Erreur : ' + response.data, 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error("Erreur AJAX:", status, error);
                button.prop('disabled', false);
                addLogEntry('Erreur de communication avec le serveur', 'error');
            }
        });
    }

    // Démarrer la synchronisation
    syncNextBatch();
});
});