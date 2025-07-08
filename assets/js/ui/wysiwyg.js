// Import Quill avec ES6
import Quill from 'quill';

// WYSIWYG Module
document.addEventListener('DOMContentLoaded', function() {
    const wysiwygs = Array.from(document.querySelectorAll('.wysiwyg-editor'));
    
    if (wysiwygs.length > 0) {
        wysiwygs.forEach((wysiwyg, index) => {
            const textarea = wysiwyg.querySelector('textarea');
            textarea.required = false;
            
            // Créer un conteneur pour Quill
            const quillContainer = document.createElement('div');
            quillContainer.classList.add('quill-container');
            wysiwyg.appendChild(quillContainer);
            
            // Cacher le textarea original
            textarea.style.display = 'none';

            // Configuration de base de Quill
            const toolbarOptions = [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                ['blockquote', 'code-block'],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                [{ 'script': 'sub'}, { 'script': 'super' }],
                [{ 'indent': '-1'}, { 'indent': '+1' }],
                [{ 'direction': 'rtl' }],
                [{ 'size': ['small', false, 'large', 'huge'] }],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'font': [] }],
                [{ 'align': [] }],
                ['link', 'image', 'video'],
                ['clean']
            ];

            const quillOptions = {
                theme: 'snow',
                modules: {
                    toolbar: toolbarOptions,
                    history: {
                        delay: 1000,
                        maxStack: 50,
                        userOnly: false
                    }
                },
                formats: [
                    'header', 'bold', 'italic', 'underline', 'strike',
                    'blockquote', 'code-block', 'list', 'bullet',
                    'script', 'indent', 'direction', 'size',
                    'color', 'background', 'font', 'align',
                    'link', 'image', 'video'
                ]
            };

            // Initialiser Quill
            const quill = new Quill(quillContainer, quillOptions);
            
            // Ajouter fonctionnalité de redimensionnement d'images custom
            addImageResizing(quill);

            // Synchroniser le contenu du textarea avec Quill
            if (textarea.value) {
                // Extraire les tailles d'images du HTML avant que Quill ne les supprime
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = textarea.value;
                const imageSizes = {};
                tempDiv.querySelectorAll('img').forEach(img => {
                    const src = img.getAttribute('src');
                    const width = img.getAttribute('width') || img.style.width;
                    const height = img.getAttribute('height') || img.style.height;
                    if (src && (width || height)) {
                        imageSizes[src] = { width, height };
                    }
                });
                
                quill.clipboard.dangerouslyPasteHTML(textarea.value);
                
                // Restaurer les tailles après le chargement
                setTimeout(() => {
                    restoreImageSizesFromData(quill, imageSizes);
                }, 100);
                
                setTimeout(() => {
                    restoreImageSizesFromData(quill, imageSizes);
                }, 500);
                
                setTimeout(() => {
                    restoreImageSizesFromData(quill, imageSizes);
                }, 1000);
                
                // Aussi écouter le chargement des images
                quill.root.addEventListener('load', function(e) {
                    if (e.target.tagName === 'IMG') {
                        restoreImageSizesFromData(quill, imageSizes);
                    }
                }, true);
            }

            // Mettre à jour le textarea quand le contenu change
            quill.on('text-change', () => {
                textarea.value = quill.root.innerHTML;
            });
            
            // Aussi écouter les changements d'attributs (pour les redimensionnements d'images)
            const observer = new MutationObserver(() => {
                textarea.value = quill.root.innerHTML;
            });
            
            observer.observe(quill.root, {
                attributes: true,
                subtree: true,
                attributeFilter: ['width', 'height', 'style']
            });

            // Synchroniser lors de la soumission du formulaire
            const form = wysiwyg.closest('form');
            if (form) {
                form.addEventListener('submit', () => {
                    textarea.value = quill.root.innerHTML;
                });
            }
        });
    }
});

// Fonction pour ajouter le redimensionnement d'images
function addImageResizing(quill) {
    // Ajouter un gestionnaire de clic sur les images
    quill.root.addEventListener('click', function(e) {
        if (e.target.tagName === 'IMG') {
            selectImage(e.target);
        }
    });
}

// Fonction pour sélectionner et redimensionner une image
function selectImage(img) {
    // Retirer toute sélection précédente
    removeImageSelection();
    
    // Ajouter une classe pour marquer l'image comme sélectionnée
    img.classList.add('ql-image-selected');
    
    // Créer un conteneur de redimensionnement en overlay
    const overlay = document.createElement('div');
    overlay.className = 'ql-image-resize-overlay';
    overlay.style.position = 'absolute';
    overlay.style.pointerEvents = 'none';
    overlay.style.zIndex = '1000';
    
    // Positionner l'overlay sur l'image
    const rect = img.getBoundingClientRect();
    const containerRect = img.closest('.quill-container').getBoundingClientRect();
    
    overlay.style.left = (rect.left - containerRect.left + img.closest('.quill-container').scrollLeft) + 'px';
    overlay.style.top = (rect.top - containerRect.top + img.closest('.quill-container').scrollTop) + 'px';
    overlay.style.width = rect.width + 'px';
    overlay.style.height = rect.height + 'px';
    
    // Ajouter l'overlay au conteneur Quill
    img.closest('.quill-container').appendChild(overlay);
    
    // Poignées de redimensionnement
    const handles = ['nw', 'ne', 'sw', 'se'];
    handles.forEach(handle => {
        const handleElement = document.createElement('div');
        handleElement.className = `ql-image-resize-handle ql-image-resize-handle-${handle}`;
        handleElement.style.pointerEvents = 'auto';
        overlay.appendChild(handleElement);
        
        // Gestionnaire de redimensionnement
        addResizeHandler(handleElement, img, handle, overlay);
    });
    
    // Barre d'outils simple
    const toolbar = document.createElement('div');
    toolbar.className = 'ql-image-resize-toolbar';
    toolbar.style.pointerEvents = 'auto';
    toolbar.innerHTML = `
        <button type="button" onclick="resizeImageTo(this, 25)">25%</button>
        <button type="button" onclick="resizeImageTo(this, 50)">50%</button>
        <button type="button" onclick="resizeImageTo(this, 75)">75%</button>
        <button type="button" onclick="resizeImageTo(this, 100)">100%</button>
    `;
    overlay.appendChild(toolbar);
    
    // Clic ailleurs pour désélectionner
    document.addEventListener('click', handleOutsideClick);
}

// Fonction pour supprimer la sélection d'image
function removeImageSelection() {
    const selectedImages = document.querySelectorAll('.ql-image-selected');
    selectedImages.forEach(img => {
        img.classList.remove('ql-image-selected');
    });
    
    // Supprimer tous les overlays de redimensionnement
    const overlays = document.querySelectorAll('.ql-image-resize-overlay');
    overlays.forEach(overlay => overlay.remove());
    
    document.removeEventListener('click', handleOutsideClick);
}

// Gestionnaire de clic à l'extérieur
function handleOutsideClick(e) {
    if (!e.target.closest('.ql-image-selected') && !e.target.closest('.ql-image-resize-overlay')) {
        removeImageSelection();
    }
}

// Ajouter gestionnaire de redimensionnement par glissement
function addResizeHandler(handle, img, position, overlay) {
    let isResizing = false;
    let startX, startY, startWidth, startHeight;
    
    handle.addEventListener('mousedown', function(e) {
        isResizing = true;
        startX = e.clientX;
        startY = e.clientY;
        startWidth = parseInt(window.getComputedStyle(img).width, 10);
        startHeight = parseInt(window.getComputedStyle(img).height, 10);
        
        document.addEventListener('mousemove', doResize);
        document.addEventListener('mouseup', stopResize);
        e.preventDefault();
    });
    
    function doResize(e) {
        if (!isResizing) return;
        
        const deltaX = e.clientX - startX;
        const deltaY = e.clientY - startY;
        
        let newWidth, newHeight;
        
        if (position.includes('e')) {
            newWidth = startWidth + deltaX;
        } else {
            newWidth = startWidth - deltaX;
        }
        
        // Maintenir les proportions
        const aspectRatio = startHeight / startWidth;
        newHeight = newWidth * aspectRatio;
        
        // Limites minimales
        if (newWidth > 50 && newHeight > 50) {
            // Utiliser les attributs HTML pour la persistance
            img.setAttribute('width', Math.round(newWidth));
            img.setAttribute('height', Math.round(newHeight));
            
            // Aussi mettre à jour le style pour l'affichage immédiat
            img.style.width = newWidth + 'px';
            img.style.height = newHeight + 'px';
            
            // Mettre à jour l'overlay
            overlay.style.width = newWidth + 'px';
            overlay.style.height = newHeight + 'px';
        }
    }
    
    function stopResize() {
        isResizing = false;
        document.removeEventListener('mousemove', doResize);
        document.removeEventListener('mouseup', stopResize);
        
        // Forcer la synchronisation avec le textarea
        const quillEditor = img.closest('.ql-editor');
        const textarea = quillEditor.closest('.wysiwyg-editor').querySelector('textarea');
        if (textarea) {
            textarea.value = quillEditor.innerHTML;
        }
        
        // Déclencher un événement de changement pour que Quill détecte la modification
        const event = new Event('input', { bubbles: true });
        quillEditor.dispatchEvent(event);
    }
}

// Fonction globale pour redimensionner à un pourcentage
window.resizeImageTo = function(button, percentage) {
    const img = document.querySelector('.ql-image-selected');
    if (img) {
        const naturalWidth = img.naturalWidth;
        const naturalHeight = img.naturalHeight;
        
        const newWidth = Math.round((naturalWidth * percentage) / 100);
        const newHeight = Math.round((naturalHeight * percentage) / 100);
        
        // Utiliser les attributs HTML pour la persistance
        img.setAttribute('width', newWidth);
        img.setAttribute('height', newHeight);
        
        // Aussi mettre à jour le style pour l'affichage immédiat
        img.style.width = newWidth + 'px';
        img.style.height = newHeight + 'px';
        
        // Mettre à jour l'overlay s'il existe
        const overlay = document.querySelector('.ql-image-resize-overlay');
        if (overlay) {
            overlay.style.width = newWidth + 'px';
            overlay.style.height = newHeight + 'px';
        }
        
        // Forcer la synchronisation avec le textarea
        const quillEditor = img.closest('.ql-editor');
        const textarea = quillEditor.closest('.wysiwyg-editor').querySelector('textarea');
        if (textarea) {
            textarea.value = quillEditor.innerHTML;
        }
        
        // Déclencher un événement de changement pour que Quill détecte la modification
        const event = new Event('input', { bubbles: true });
        quillEditor.dispatchEvent(event);
    }
};

// Fonction pour restaurer les tailles d'images depuis les données sauvegardées
function restoreImageSizesFromData(quill, imageSizes) {
    try {
        const images = quill.root.querySelectorAll('img');
        
        images.forEach((img, index) => {
            const src = img.getAttribute('src');
            if (src && imageSizes[src]) {
                const sizeData = imageSizes[src];
                
                // Convertir les valeurs en pixels si nécessaire
                let width = sizeData.width;
                let height = sizeData.height;
                
                // Si la valeur contient déjà 'px', la retirer
                if (typeof width === 'string' && width.includes('px')) {
                    width = parseInt(width);
                }
                if (typeof height === 'string' && height.includes('px')) {
                    height = parseInt(height);
                }
                
                if (width) {
                    img.setAttribute('width', width);
                    img.style.width = width + 'px';
                    img.style.setProperty('width', width + 'px', 'important');
                }
                
                if (height) {
                    img.setAttribute('height', height);
                    img.style.height = height + 'px';
                    img.style.setProperty('height', height + 'px', 'important');
                }
            }
        });
        
    } catch (error) {
        // Silencier les erreurs en production
    }
}

// Fonction pour restaurer les tailles d'images depuis les attributs HTML
function restoreImageSizes(quill) {
    try {
        const images = quill.root.querySelectorAll('img');
        
        images.forEach((img, index) => {
            const width = img.getAttribute('width');
            const height = img.getAttribute('height');
            
            
            if (width) {
                img.style.width = width + 'px';
                img.style.setProperty('width', width + 'px', 'important');
            }
            
            if (height) {
                img.style.height = height + 'px';
                img.style.setProperty('height', height + 'px', 'important');
            }
            
        });
        
        // Forcer une reprise de peinture
        quill.root.style.display = 'none';
        quill.root.offsetHeight; // Trigger reflow
        quill.root.style.display = '';
        
        
    } catch (error) {
        // Silencier les erreurs en production
    }
}