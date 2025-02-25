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
    $('input[type="password"]').each(function() {
        const input = $(this);
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

    // Synchronisation d'un produit individuel (depuis la liste ou la métabox)
    $(document).on('click', '.pennylane-sync-product, #pennylane-sync-product', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const productId = button.data('product-id');
        
        if (!productId) {
            return;
        }
        
        button.prop('disabled', true);
        
        // Ajouter un spinner
        if (button.next('.spinner').length === 0) {
            button.after('<span class="spinner is-active"></span>');
        } else {
            button.next('.spinner').addClass('is-active');
        }
        
        $.ajax({
            url: wooPennylaneParams.ajaxUrl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_sync_single_product',
                nonce: wooPennylaneParams.nonce,
                product_id: productId
            },
            success: function(response) {
                button.next('.spinner').removeClass('is-active');
                
                if (response.success) {
                    // Si nous sommes dans la métabox
                    if (button.attr('id') === 'pennylane-sync-product') {
                        const container = button.closest('.pennylane-product-sync-box');
                        container.find('.pennylane-not-synced').removeClass('pennylane-not-synced').addClass('pennylane-synced').text('Synchronisé');
                        container.find('.pennylane-sync-error').remove();
                        
                        // Mise à jour des informations
                        if (container.find('p:contains("ID Pennylane")').length === 0) {
                            container.prepend('<p>' + wooPennylaneParams.i18n.pennylane_id + ': <strong>' + response.data.pennylane_id + '</strong></p>');
                        }
                        
                        // Ajouter un message de succès temporaire
                        button.after('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                        setTimeout(function() {
                            button.next('.notice').fadeOut(function() {
                                $(this).remove();
                            });
                        }, 3000);
                    } 
                    // Si nous sommes dans la liste des produits
                    else {
                        const cell = button.parent();
                        cell.find('.dashicons').remove();
                        cell.prepend('<span class="dashicons dashicons-yes" title="' + wooPennylaneParams.i18n.synced + '"></span> ');
                        
                        // Ajouter un message de succès temporaire
                        button.after('<span class="sync-success">✓</span>');
                        setTimeout(function() {
                            button.next('.sync-success').fadeOut(function() {
                                $(this).remove();
                            });
                        }, 2000);
                    }
                } else {
                    // Afficher l'erreur
                    if (button.attr('id') === 'pennylane-sync-product') {
                        button.after('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                        setTimeout(function() {
                            button.next('.notice').fadeOut(function() {
                                $(this).remove();
                            });
                        }, 3000);
                    } else {
                        button.after('<span class="sync-error">✗</span>');
                        setTimeout(function() {
                            button.next('.sync-error').fadeOut(function() {
                                $(this).remove();
                            });
                        }, 2000);
                    }
                }
            },
            error: function() {
                button.next('.spinner').removeClass('is-active');
                alert('Erreur de communication avec le serveur');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});