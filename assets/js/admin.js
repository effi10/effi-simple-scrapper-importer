/**
 * FICHIER: assets/js/admin.js
 * Gestion de l'import asynchrone avec AJAX et barre de progression
 */

(function($) {
    'use strict';
    
    let isProcessing = false;
    let totalFiles = 0;
    let processedFiles = 0;
    let successCount = 0;
    let errorCount = 0;
    let errors = [];
    
    /**
     * Initialisation
     */
    $(document).ready(function() {
        $('#effissi-import-form').on('submit', handleFormSubmit);
        
        // Afficher le nombre de fichiers sélectionnés
        $('#effissi-files').on('change', function() {
            const fileCount = this.files.length;
            if (fileCount > 0) {
                const text = fileCount === 1 
                    ? '1 fichier sélectionné' 
                    : fileCount + ' fichiers sélectionnés';
                
                if (!$('#effissi-file-count').length) {
                    $(this).after('<p id="effissi-file-count" class="description" style="margin-top: 8px; font-weight: 600; color: #2271b1;"></p>');
                }
                $('#effissi-file-count').text(text);
            } else {
                $('#effissi-file-count').remove();
            }
        });
    });
    
    /**
     * Gérer la soumission du formulaire
     */
    function handleFormSubmit(e) {
        e.preventDefault();
        
        if (isProcessing) {
            return;
        }
        
        const files = $('#effissi-files')[0].files;
        const postStatus = $('input[name="post_status"]:checked').val();
        const attachImages = $('#effissi-attach-images').is(':checked');
        
        if (files.length === 0) {
            alert('Veuillez sélectionner au moins un fichier.');
            return;
        }
        
        // Réinitialiser les compteurs
        totalFiles = files.length;
        processedFiles = 0;
        successCount = 0;
        errorCount = 0;
        errors = [];
        
        // Préparer l'interface
        startImport();
        
        // Traiter les fichiers un par un
        processFilesSequentially(files, postStatus, attachImages);
    }
    
    /**
     * Préparer l'interface pour l'import
     */
    function startImport() {
        isProcessing = true;
        
        // Désactiver le formulaire
        $('#effissi-import-btn').prop('disabled', true).text('Import en cours...');
        $('#effissi-files').prop('disabled', true);
        $('input[name="post_status"]').prop('disabled', true);
        $('#effissi-attach-images').prop('disabled', true);
        
        // Afficher la barre de progression
        $('#effissi-progress-container').slideDown();
        $('#effissi-results').hide();
        
        updateProgress(0, 'Démarrage de l\'import...');
    }
    
    /**
     * Traiter les fichiers séquentiellement (un par un)
     */
    function processFilesSequentially(files, postStatus, attachImages) {
        const filesArray = Array.from(files);
        
        // Fonction récursive pour traiter chaque fichier
        function processNext(index) {
            if (index >= filesArray.length) {
                // Tous les fichiers ont été traités
                finishImport();
                return;
            }
            
            const file = filesArray[index];
            const fileName = file.name;
            
            updateProgress(
                (index / totalFiles) * 100,
                effissiAjax.strings.processing + ' ' + (index + 1) + ' ' + 
                effissiAjax.strings.of + ' ' + totalFiles + ': ' + fileName
            );
            
            // Envoyer le fichier via AJAX
            importSingleFile(file, postStatus, attachImages)
                .then(function(response) {
                    processedFiles++;
                    
                    if (response.success) {
                        successCount++;
                    } else {
                        errorCount++;
                        errors.push({
                            file: fileName,
                            message: response.data.message || 'Erreur inconnue'
                        });
                    }
                    
                    // Mettre à jour les stats
                    updateStats();
                    
                    // Traiter le fichier suivant
                    processNext(index + 1);
                })
                .catch(function(error) {
                    processedFiles++;
                    errorCount++;
                    errors.push({
                        file: fileName,
                        message: error.message || 'Erreur réseau'
                    });
                    
                    updateStats();
                    processNext(index + 1);
                });
        }
        
        // Démarrer le traitement
        processNext(0);
    }
    
    /**
     * Importer un seul fichier via AJAX
     */
    function importSingleFile(file, postStatus, attachImages) {
        return new Promise(function(resolve, reject) {
            const formData = new FormData();
            formData.append('action', 'effissi_import_file');
            formData.append('nonce', effissiAjax.nonce);
            formData.append('file', file);
            formData.append('post_status', postStatus);
            formData.append('attach_images', attachImages ? '1' : '0');
            
            $.ajax({
                url: effissiAjax.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 60000, // 60 secondes max par fichier
                success: function(response) {
                    resolve(response);
                },
                error: function(xhr, status, error) {
                    reject(new Error(error));
                }
            });
        });
    }
    
    /**
     * Mettre à jour la barre de progression
     */
    function updateProgress(percentage, text) {
        const roundedPercentage = Math.round(percentage);
        $('#effissi-progress-fill').css('width', roundedPercentage + '%');
        $('#effissi-progress-text').text(text);
    }
    
    /**
     * Mettre à jour les statistiques
     */
    function updateStats() {
        const statsHtml = 
            '<strong>Progression:</strong> ' + processedFiles + ' / ' + totalFiles + ' | ' +
            '<span style="color: #00a32a;">✓ ' + successCount + ' réussis</span> | ' +
            '<span style="color: #d63638;">✗ ' + errorCount + ' échecs</span>';
        
        $('#effissi-progress-stats').html(statsHtml);
    }
    
    /**
     * Terminer l'import et afficher les résultats
     */
    function finishImport() {
        isProcessing = false;
        
        // Mettre à jour la progression à 100%
        updateProgress(100, effissiAjax.strings.completed);
        
        // Afficher les résultats
        setTimeout(function() {
            displayResults();
            resetForm();
        }, 500);
    }
    
    /**
     * Afficher les résultats finaux
     */
    function displayResults() {
        let resultClass = 'success';
        let resultTitle = 'Import terminé avec succès !';
        
        if (errorCount > 0 && successCount > 0) {
            resultClass = 'partial';
            resultTitle = 'Import terminé avec des erreurs';
        } else if (errorCount > 0 && successCount === 0) {
            resultClass = 'error';
            resultTitle = 'Échec de l\'import';
        }
        
        let html = '<h3>' + resultTitle + '</h3>';
        html += '<p><strong>' + successCount + '</strong> ' + effissiAjax.strings.success;
        
        if (errorCount > 0) {
            html += ' | <strong>' + errorCount + '</strong> ' + effissiAjax.strings.failed;
        }
        
        html += '</p>';
        
        // Afficher les erreurs si présentes
        if (errors.length > 0) {
            html += '<details style="margin-top: 15px;">';
            html += '<summary style="cursor: pointer; font-weight: 600; margin-bottom: 10px;">Voir les erreurs</summary>';
            html += '<ul style="margin: 10px 0 0 20px;">';
            
            errors.forEach(function(error) {
                html += '<li style="margin-bottom: 5px;">';
                html += '<strong>' + escapeHtml(error.file) + ':</strong> ' + escapeHtml(error.message);
                html += '</li>';
            });
            
            html += '</ul>';
            html += '</details>';
        }
        
        $('#effissi-results')
            .removeClass('success error partial')
            .addClass(resultClass)
            .html(html)
            .slideDown();
    }
    
    /**
     * Réinitialiser le formulaire
     */
    function resetForm() {
        $('#effissi-import-btn').prop('disabled', false).text('Importer');
        $('#effissi-files').prop('disabled', false).val('');
        $('input[name="post_status"]').prop('disabled', false);
        $('#effissi-attach-images').prop('disabled', false);
        $('#effissi-file-count').remove();
        
        // Masquer la barre de progression après 2 secondes
        setTimeout(function() {
            $('#effissi-progress-container').slideUp();
        }, 2000);
    }
    
    /**
     * Échapper le HTML pour éviter les injections XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
})(jQuery);