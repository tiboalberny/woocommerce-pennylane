jQuery(document).ready(function($) {
    console.log("WooPennylane User Sync JS loaded");
    
    // Gestion de la synchronisation des clients depuis la liste des utilisateurs
    $(document).on('click', '.sync-pennylane-customer', function(e) {
        e.preventDefault();
        console.log("Sync button clicked");
        
        const button = $(this);
        const userId = button.data('user-id');
        const nonce = button.data('nonce');
        
        console.log("User ID:", userId);
        
        const spinner = button.next('.spinner');
        const resultSpan = button.siblings('.sync-result');
        const isResync = button.hasClass('resync');
        
        // Désactiver le bouton et afficher le spinner
        button.prop('disabled', true);
        spinner.addClass('is-active');
        resultSpan.empty();
        
        // Envoyer la requête AJAX
        $.ajax({
            url: ajaxurl, // Variable globale WordPress
            type: 'POST',
            data: {
                action: 'woo_pennylane_sync_single_customer',
                nonce: nonce,
                customer_id: userId,
                force_resync: isResync ? 'yes' : 'no'
            },
            success: function(response) {
                console.log("AJAX response:", response);
                spinner.removeClass('is-active');
                
                if (response.success) {
                    resultSpan.html('<span class="sync-success" style="color:green;">' + response.data.message + '</span>');
                    
                    // Rafraîchir la page après un court délai
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    resultSpan.html('<span class="sync-error" style="color:red;">' + (response.data ? response.data.message : 'Erreur inconnue') + '</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX error:", status, error);
                console.error("Response:", xhr.responseText);
                spinner.removeClass('is-active');
                resultSpan.html('<span class="sync-error" style="color:red;">Erreur de communication avec le serveur</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});