/**
 * WC Admin Order Notes - Modern JavaScript (REST API Only)
 * Version: 2.2.1
 */

(function() {
    'use strict';
    
    // State management
    const state = {
        currentOrderId: null,
        isLoading: false,
        debounceTimer: null,
        maxNoteLength: wcOrderNotes.maxNoteLength || 1000
    };
    
    // DOM elements cache
    const elements = {
        modal: null,
        modalContent: null,
        closeBtn: null,
        notesList: null,
        addNoteBtn: null,
        newNoteTextarea: null,
        modalTitle: null
    };
    
    /**
     * Initialize the application
     */
    function init() {
        // Cache DOM elements
        cacheElements();
        
        // Bind events
        bindEvents();
    }
    
    /**
     * Cache DOM elements
     */
    function cacheElements() {
        elements.modal = document.getElementById('order-notes-modal');
        elements.modalContent = document.querySelector('.order-notes-modal-content');
        elements.closeBtn = document.querySelector('.order-notes-modal-close');
        elements.notesList = document.getElementById('notes-list');
        elements.addNoteBtn = document.getElementById('add-note-btn');
        elements.newNoteTextarea = document.getElementById('new-note-content');
        elements.modalTitle = document.getElementById('modal-title');
        elements.characterCount = document.getElementById('character-count');
    }
    
    /**
     * Bind event listeners
     */
    function bindEvents() {
        // Note preview clicks with event delegation
        document.addEventListener('click', handleDocumentClick, true);
        
        // Modal close button
        if (elements.closeBtn) {
            elements.closeBtn.addEventListener('click', closeModal);
        }
        
        // Click outside modal
        if (elements.modal) {
            elements.modal.addEventListener('click', handleModalBackdropClick);
        }
        
        // Escape key
        document.addEventListener('keydown', handleKeyDown);
        
        // Add note button
        if (elements.addNoteBtn) {
            elements.addNoteBtn.addEventListener('click', handleAddNote);
        }
        
        // Enter key in textarea
        if (elements.newNoteTextarea) {
            elements.newNoteTextarea.addEventListener('keydown', handleTextareaKeyDown);
            elements.newNoteTextarea.addEventListener('input', handleTextareaInput);
        }
    }
    
    /**
     * Handle document clicks
     */
    function handleDocumentClick(e) {
        if (!e || !e.target) {
            return;
        }
        
        // Check if click is on note preview or its children
        const notePreview = e.target.closest('.note-preview');
        
        if (notePreview) {
            // Stop all propagation to prevent WooCommerce from handling the click
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            const orderId = notePreview.dataset.orderId;
            if (orderId) {
                state.currentOrderId = orderId;
                openModal();
                loadOrderNotes(orderId);
            }
            
            return false;
        }
        
        // Also handle clicks on the order-notes-cell container
        const orderNotesCell = e.target.closest('.order-notes-cell');
        if (orderNotesCell) {
            const notePreviewInCell = orderNotesCell.querySelector('.note-preview');
            if (notePreviewInCell) {
                // Stop all propagation to prevent WooCommerce from handling the click
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                
                const orderId = notePreviewInCell.dataset.orderId;
                if (orderId) {
                    state.currentOrderId = orderId;
                    openModal();
                    loadOrderNotes(orderId);
                }
                
                return false;
            }
        }
    }
    
    /**
     * Handle modal backdrop click
     */
    function handleModalBackdropClick(e) {
        if (e.target === elements.modal) {
            closeModal();
        }
    }
    
    /**
     * Handle keyboard events
     */
    function handleKeyDown(e) {
        if (e.key === 'Escape' && elements.modal && elements.modal.style.display !== 'none') {
            closeModal();
        }
    }
    
    /**
     * Handle textarea keyboard events
     */
    function handleTextareaKeyDown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            
            // Debounce the add note action
            if (state.debounceTimer) {
                clearTimeout(state.debounceTimer);
            }
            
            state.debounceTimer = setTimeout(() => {
                handleAddNote();
            }, 300);
        }
    }
    
    /**
     * Handle textarea input for character count
     */
    function handleTextareaInput(e) {
        if (!elements.characterCount) return;
        
        const length = e.target.value.length;
        const maxLength = state.maxNoteLength;
        
        elements.characterCount.textContent = `${length} / ${maxLength}`;
        
        // Update styling based on character count
        elements.characterCount.className = 'character-count';
        if (length > maxLength * 0.9) {
            elements.characterCount.classList.add('warning');
        }
        if (length >= maxLength) {
            elements.characterCount.classList.add('error');
        }
    }
    
    /**
     * Open modal
     */
    function openModal() {
        if (!elements.modal || !elements.modalContent) return;
        
        elements.modal.style.display = 'block';
        elements.modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        
        // Trigger reflow for animation
        elements.modal.offsetHeight;
        
        elements.modalContent.classList.add('show');
        
        // Focus on textarea after animation
        setTimeout(() => {
            if (elements.newNoteTextarea) {
                elements.newNoteTextarea.focus();
            }
        }, 350);
    }
    
    /**
     * Close modal
     */
    function closeModal() {
        if (!elements.modal || !elements.modalContent) return;
        
        elements.modalContent.classList.remove('show');
        
        setTimeout(() => {
            elements.modal.style.display = 'none';
            elements.modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
            
            // Clear form
            if (elements.newNoteTextarea) {
                elements.newNoteTextarea.value = '';
            }
            if (elements.notesList) {
                elements.notesList.innerHTML = '';
            }
            state.currentOrderId = null;
        }, 300);
    }
    
    /**
     * Load order notes via REST API
     */
    async function loadOrderNotes(orderId) {
        if (!elements.notesList) return;
        
        showLoadingState();
        
        try {
            const data = await fetchNotesViaRest(orderId);
            
            if (data.notes) {
                displayNotes(data.notes, data.order_number);
            }
        } catch (error) {
            console.error('Error loading notes:', error);
            showError(error.message || wcOrderNotes.strings.error);
        }
    }
    
    /**
     * Fetch notes via REST API
     */
    async function fetchNotesViaRest(orderId) {
        // Validate order ID
        if (!orderId || !/^\d+$/.test(orderId)) {
            throw new Error('Invalid order ID');
        }
        
        try {
            const response = await fetch(`${wcOrderNotes.restUrl}/notes/${orderId}`, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': wcOrderNotes.restNonce,
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                let errorMessage = 'Failed to load notes';
                
                try {
                    const error = await response.json();
                    errorMessage = error.message || errorMessage;
                } catch (e) {
                    // If response is not JSON, use status text
                    errorMessage = response.statusText || errorMessage;
                }
                
                // Handle specific HTTP status codes
                if (response.status === 403) {
                    errorMessage = wcOrderNotes.strings.securityError || 'Security check failed';
                } else if (response.status === 404) {
                    errorMessage = 'Order not found';
                } else if (response.status === 429) {
                    errorMessage = 'Too many requests. Please try again later.';
                }
                
                throw new Error(errorMessage);
            }
            
            return await response.json();
            
        } catch (error) {
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                throw new Error('Network error. Please check your connection.');
            }
            throw error;
        }
    }
    
    /**
     * Display notes
     */
    function displayNotes(notes, orderNumber) {
        if (!elements.notesList || !elements.modalTitle) return;
        
        elements.modalTitle.textContent = `${wcOrderNotes.strings.orderNotes} #${orderNumber}`;
        
        if (notes.length === 0) {
            const noNotesDiv = document.createElement('div');
            noNotesDiv.className = 'no-notes';
            noNotesDiv.textContent = wcOrderNotes.strings.noNotes;
            elements.notesList.innerHTML = '';
            elements.notesList.appendChild(noNotesDiv);
            return;
        }
        
        const container = document.createElement('div');
        container.className = 'notes-container';
        
        notes.forEach(note => {
            const noteItem = document.createElement('div');
            noteItem.className = `note-item ${escapeHtml(note.type)}-note`;
            
            const noteContent = document.createElement('div');
            noteContent.className = 'note-content';
            noteContent.textContent = note.content;
            
            const noteMeta = document.createElement('div');
            noteMeta.className = 'note-meta';
            
            const noteAuthor = document.createElement('span');
            noteAuthor.className = 'note-author';
            noteAuthor.textContent = note.author;
            
            const noteDate = document.createElement('span');
            noteDate.className = 'note-date';
            noteDate.textContent = note.date;
            
            noteMeta.appendChild(noteAuthor);
            noteMeta.appendChild(noteDate);
            
            noteItem.appendChild(noteContent);
            noteItem.appendChild(noteMeta);
            container.appendChild(noteItem);
        });
        
        elements.notesList.innerHTML = '';
        elements.notesList.appendChild(container);
    }
    
    /**
     * Handle add note
     */
    async function handleAddNote() {
        if (!elements.newNoteTextarea || !elements.addNoteBtn) return;
        
        const noteContent = elements.newNoteTextarea.value.trim();
        
        if (!noteContent) {
            showNotification(wcOrderNotes.strings.notePlaceholder || 'Please enter a note.', 'error');
            return;
        }
        
        if (noteContent.length > state.maxNoteLength) {
            showNotification(`Note content is too long. Maximum ${state.maxNoteLength} characters allowed.`, 'error');
            return;
        }
        
        if (!state.currentOrderId) {
            showNotification('No order selected.', 'error');
            return;
        }
        
        setButtonLoading(true);
        
        try {
            const result = await addNoteViaRest(state.currentOrderId, noteContent);
            
            if (result.message) {
                // Clear textarea
                elements.newNoteTextarea.value = '';
                
                // Show success message
                showNotification(wcOrderNotes.strings.noteAdded, 'success');
                
                // Reload notes with cache busting
                await loadOrderNotesWithCacheBust(state.currentOrderId);
                
                // Update the order list preview
                updateOrderListPreview(state.currentOrderId);
            }
        } catch (error) {
            console.error('Error adding note:', error);
            showNotification(error.message || wcOrderNotes.strings.error, 'error');
        } finally {
            setButtonLoading(false);
        }
    }
    
    /**
     * Add note via REST API
     */
    async function addNoteViaRest(orderId, noteContent) {
        // Validate order ID
        if (!orderId || !/^\d+$/.test(orderId)) {
            throw new Error('Invalid order ID');
        }
        
        // Sanitize note content - remove HTML tags and limit length
        const sanitizedContent = noteContent.replace(/<[^>]*>/g, '').substring(0, state.maxNoteLength);
        
        try {
            const response = await fetch(`${wcOrderNotes.restUrl}/notes/${orderId}`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': wcOrderNotes.restNonce,
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    note_content: sanitizedContent
                }),
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                let errorMessage = 'Failed to add note';
                
                try {
                    const error = await response.json();
                    errorMessage = error.message || errorMessage;
                } catch (e) {
                    errorMessage = response.statusText || errorMessage;
                }
                
                // Handle specific HTTP status codes
                if (response.status === 400) {
                    errorMessage = wcOrderNotes.strings.contentTooLong || 'Note content is too long';
                } else if (response.status === 403) {
                    errorMessage = wcOrderNotes.strings.securityError || 'Security check failed';
                } else if (response.status === 404) {
                    errorMessage = 'Order not found';
                } else if (response.status === 429) {
                    errorMessage = 'Too many requests. Please try again later.';
                }
                
                throw new Error(errorMessage);
            }
            
            return await response.json();
            
        } catch (error) {
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                throw new Error('Network error. Please check your connection.');
            }
            throw error;
        }
    }
    
    /**
     * Load order notes with cache busting
     */
    async function loadOrderNotesWithCacheBust(orderId) {
        if (!elements.notesList) return;
        
        showLoadingState();
        
        try {
            const data = await fetchNotesViaRestWithCacheBust(orderId);
            
            if (data.notes) {
                displayNotes(data.notes, data.order_number);
            }
        } catch (error) {
            console.error('Error loading notes:', error);
            showError(error.message || wcOrderNotes.strings.error);
        }
    }
    
    /**
     * Fetch notes via REST API with cache busting
     */
    async function fetchNotesViaRestWithCacheBust(orderId) {
        const timestamp = Date.now();
        const response = await fetch(`${wcOrderNotes.restUrl}/notes/${orderId}?_=${timestamp}`, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': wcOrderNotes.restNonce,
                'Content-Type': 'application/json',
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to load notes');
        }
        
        return await response.json();
    }
    
    /**
     * Update order list preview without full page reload
     */
    function updateOrderListPreview(orderId) {
        // Find the note preview in the order list
        const notePreview = document.querySelector(`[data-order-id="${orderId}"]`);
        if (!notePreview) return;
        
        // Store original content safely
        const originalContent = notePreview.cloneNode(true);
        
        // Add a subtle loading indicator
        const loadingSpan = document.createElement('span');
        loadingSpan.style.opacity = '0.6';
        loadingSpan.textContent = 'Updating...';
        notePreview.innerHTML = '';
        notePreview.appendChild(loadingSpan);
        
        // Fetch the latest note for preview
        fetchLatestNoteForPreview(orderId).then(latestNote => {
            if (latestNote && notePreview) {
                notePreview.innerHTML = '';
                
                if (latestNote.content) {
                    const noteContentDiv = document.createElement('div');
                    noteContentDiv.className = 'note-content';
                    
                    const noteContent = latestNote.content.length > 50 
                        ? latestNote.content.substring(0, 50) + '...' 
                        : latestNote.content;
                    noteContentDiv.textContent = noteContent;
                    
                    const noteDateSmall = document.createElement('small');
                    noteDateSmall.className = 'note-date';
                    noteDateSmall.textContent = formatDateShort(latestNote.date);
                    
                    notePreview.appendChild(noteContentDiv);
                    notePreview.appendChild(noteDateSmall);
                    notePreview.classList.remove('no-notes');
                } else {
                    const noNotesEm = document.createElement('em');
                    noNotesEm.textContent = 'No notes';
                    notePreview.appendChild(noNotesEm);
                    notePreview.classList.add('no-notes');
                }
                
                // Add a brief highlight effect
                notePreview.style.backgroundColor = '#e7f3ff';
                setTimeout(() => {
                    notePreview.style.backgroundColor = '';
                }, 1000);
            }
        }).catch(() => {
            // If failed, restore original content
            if (notePreview && originalContent) {
                notePreview.innerHTML = '';
                notePreview.appendChild(originalContent.cloneNode(true));
            }
        });
    }
    
    /**
     * Fetch latest note for preview update
     */
    async function fetchLatestNoteForPreview(orderId) {
        try {
            const data = await fetchNotesViaRestWithCacheBust(orderId);
            return data.notes && data.notes.length > 0 ? data.notes[0] : null;
        } catch (error) {
            console.error('Error fetching latest note:', error);
            return null;
        }
    }
    
    /**
     * Format date for short display
     */
    function formatDateShort(dateString) {
        try {
            const date = new Date(dateString);
            const today = new Date();
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            
            if (date.toDateString() === today.toDateString()) {
                return 'Today';
            } else if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday';
            } else {
                return date.toLocaleDateString();
            }
        } catch (error) {
            return dateString;
        }
    }
    
    /**
     * Show loading state
     */
    function showLoadingState() {
        if (elements.notesList) {
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'loading';
            loadingDiv.textContent = wcOrderNotes.strings.loading;
            elements.notesList.innerHTML = '';
            elements.notesList.appendChild(loadingDiv);
        }
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        if (elements.notesList) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error';
            errorDiv.textContent = message;
            elements.notesList.innerHTML = '';
            elements.notesList.appendChild(errorDiv);
        }
    }
    
    /**
     * Set button loading state
     */
    function setButtonLoading(isLoading) {
        if (!elements.addNoteBtn) return;
        
        elements.addNoteBtn.disabled = isLoading;
        elements.addNoteBtn.textContent = isLoading 
            ? wcOrderNotes.strings.loading 
            : wcOrderNotes.strings.addNote;
    }
    
    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        // Sanitize message
        const sanitizedMessage = escapeHtml(String(message));
        
        const notification = document.createElement('div');
        notification.className = `order-notes-notification ${type}`;
        notification.textContent = sanitizedMessage;
        notification.setAttribute('role', 'alert');
        notification.setAttribute('aria-live', 'polite');
        
        document.body.appendChild(notification);
        
        // Trigger reflow for animation
        notification.offsetHeight;
        
        notification.style.display = 'block';
        
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }, 3000);
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (typeof text !== 'string') {
            text = String(text);
        }
        
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
            '/': '&#x2F;',
            '`': '&#x60;',
            '=': '&#x3D;'
        };
        
        return text.replace(/[&<>"'`=\/]/g, m => map[m]);
    }
    
    /**
     * Add hover effects via JavaScript
     */
    function addHoverEffects() {
        document.addEventListener('mouseenter', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('note-preview')) {
                e.target.classList.add('hover');
            }
        }, true);
        
        document.addEventListener('mouseleave', function(e) {
            if (e.target && e.target.classList && e.target.classList.contains('note-preview')) {
                e.target.classList.remove('hover');
            }
        }, true);
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Add hover effects
    addHoverEffects();
    
    // Export for debugging
    window.WCAdminOrderNotes = {
        state,
        openModal,
        closeModal,
        loadOrderNotes
    };
})(); 