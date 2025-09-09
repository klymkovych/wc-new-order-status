/**
 * WC Admin Order Notes - Modern JavaScript (REST API Only)
 * Version: 2.2.0
 */

(function() {
    'use strict';
    
    // State management
    const state = {
        currentOrderId: null,
        isLoading: false
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
            if (notePreviewInCell && e.target !== notePreviewInCell && !notePreviewInCell.contains(e.target)) {
                return;
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
            handleAddNote();
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
        const response = await fetch(`${wcOrderNotes.restUrl}/notes/${orderId}`, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': wcOrderNotes.restNonce,
                'Content-Type': 'application/json',
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
     * Display notes
     */
    function displayNotes(notes, orderNumber) {
        if (!elements.notesList || !elements.modalTitle) return;
        
        elements.modalTitle.textContent = `${wcOrderNotes.strings.orderNotes} #${orderNumber}`;
        
        if (notes.length === 0) {
            elements.notesList.innerHTML = `<div class="no-notes">${wcOrderNotes.strings.noNotes}</div>`;
            return;
        }
        
        const notesHtml = notes.map(note => `
            <div class="note-item ${note.type}-note">
                <div class="note-content">${escapeHtml(note.content)}</div>
                <div class="note-meta">
                    <span class="note-author">${escapeHtml(note.author)}</span>
                    <span class="note-date">${escapeHtml(note.date)}</span>
                </div>
            </div>
        `).join('');
        
        elements.notesList.innerHTML = `<div class="notes-container">${notesHtml}</div>`;
    }
    
    /**
     * Handle add note
     */
    async function handleAddNote() {
        if (!elements.newNoteTextarea || !elements.addNoteBtn) return;
        
        const noteContent = elements.newNoteTextarea.value.trim();
        
        if (!noteContent) {
            alert(wcOrderNotes.strings.notePlaceholder || 'Please enter a note.');
            return;
        }
        
        if (!state.currentOrderId) {
            alert('No order selected.');
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
        const response = await fetch(`${wcOrderNotes.restUrl}/notes/${orderId}`, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wcOrderNotes.restNonce,
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                note_content: noteContent
            }),
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to add note');
        }
        
        return await response.json();
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
        
        // Add a subtle loading indicator
        const originalContent = notePreview.innerHTML;
        notePreview.innerHTML = '<span style="opacity: 0.6;">Updating...</span>';
        
        // Fetch the latest note for preview
        fetchLatestNoteForPreview(orderId).then(latestNote => {
            if (latestNote && notePreview) {
                if (latestNote.content) {
                    const noteContent = latestNote.content.length > 50 
                        ? latestNote.content.substring(0, 50) + '...' 
                        : latestNote.content;
                    const noteDate = formatDateShort(latestNote.date);
                    
                    notePreview.innerHTML = `
                        <div class="note-content">${escapeHtml(noteContent)}</div>
                        <small class="note-date">${escapeHtml(noteDate)}</small>
                    `;
                    notePreview.classList.remove('no-notes');
                } else {
                    notePreview.innerHTML = '<em>No notes</em>';
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
            if (notePreview) {
                notePreview.innerHTML = originalContent;
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
            elements.notesList.innerHTML = `<div class="loading">${wcOrderNotes.strings.loading}</div>`;
        }
    }
    
    /**
     * Show error message
     */
    function showError(message) {
        if (elements.notesList) {
            elements.notesList.innerHTML = `<div class="error">${escapeHtml(message)}</div>`;
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
        const notification = document.createElement('div');
        notification.className = `order-notes-notification ${type}`;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        // Trigger reflow for animation
        notification.offsetHeight;
        
        notification.style.display = 'block';
        
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => {
                notification.remove();
            }, 300);
        }, 3000);
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
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