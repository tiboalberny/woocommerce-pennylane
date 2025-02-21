jQuery(document).ready(function($) {
    // Configuration de base
    const config = {
        messageTimeout: 3000,
        animationSpeed: 400
    };

    // Classe pour gérer les notifications
    class NotificationManager {
        constructor(parent) {
            this.parent = parent;
        }

        show(message, type) {
            const messageElement = $('<span>', {
                class: `woo-pennylane-${type}`,
                text: message
            });

            this.parent.after(messageElement);

            setTimeout(() => {
                messageElement.fadeOut(config.animationSpeed, function() {
                    $(this).remove();
                });
            }, config.messageTimeout);
        }
    }

    // Gestion du bouton de resynchronisation
    $('.woo-pennylane-resync').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const orderId = button.data('order-id');
        const nonce = button.data('nonce');
        const notificationManager = new NotificationManager(button);
        
        // Désactive le bouton et ajoute un spinner
        button.prop('disabled', true);
        const spinner = $('<span>', {
            class: 'woo-pennylane-spinner spinner is-active'
        });
        button.after(spinner);

        // Appel AJAX pour la resynchronisation
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_resync_order',
                order_id: orderId,
                nonce: nonce
            },
            success: function(response) {
                spinner.remove();
                notificationManager.show(
                    response.message,
                    response.success ? 'success' : 'error'
                );
                button.prop('disabled', false);
            },
            error: function() {
                spinner.remove();
                notificationManager.show(
                    wooPennylaneParams.errorMessage,
                    'error'
                );
                button.prop('disabled', false);
            }
        });
    });

    // Validation des paramètres
    $('#woo-pennylane-settings-form').on('submit', function(e) {
        const apiKey = $('#woo_pennylane_api_key').val();
        const journalCode = $('#woo_pennylane_journal_code').val();
        const accountNumber = $('#woo_pennylane_account_number').val();

        if (!apiKey || !journalCode || !accountNumber) {
            e.preventDefault();
            alert(wooPennylaneParams.requiredFieldsMessage);
            return false;
        }
    });

    // Test de connexion API
    $('#woo-pennylane-test-connection').on('click', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const notificationManager = new NotificationManager(button);
        
        button.prop('disabled', true);
        const spinner = $('<span>', {
            class: 'woo-pennylane-spinner spinner is-active'
        });
        button.after(spinner);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'woo_pennylane_test_connection',
                nonce: button.data('nonce'),
                api_key: $('#woo_pennylane_api_key').val()
            },
            success: function(response) {
                spinner.remove();
                notificationManager.show(
                    response.message,
                    response.success ? 'success' : 'error'
                );
                button.prop('disabled', false);
            },
            error: function() {
                spinner.remove();
                notificationManager.show(
                    wooPennylaneParams.connectionErrorMessage,
                    'error'
                );
                button.prop('disabled', false);
            }
        });
    });

    // Masquer/Afficher la clé API
    $('.woo-pennylane-toggle-visibility').on('click', function(e) {
        e.preventDefault();
        const input = $('#woo_pennylane_api_key');
        const type = input.attr('type');
        
        input.attr('type', type === 'password' ? 'text' : 'password');
        $(this).text(type === 'password' ? 
            wooPennylaneParams.hideText : 
            wooPennylaneParams.showText
        );
    });
});