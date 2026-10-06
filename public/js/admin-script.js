/**
 * WebDide Card-to-Card Verification Admin JavaScript
 * Handles modal functionality, guide popups, and clipboard operations
 */

(function($) {
    'use strict';

    var currentModalOrders = [];
    var itemsPerPage = 10;
    var currentModalPage = 1;
    var currentCardLabel = "";

    window.copyToClipboard = function(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                alert(wdcv_admin_vars.copied_text + " " + text);
            }).catch(function(err) {
                console.error('Failed to copy: ', err);
                fallbackCopyTextToClipboard(text);
            });
        } else {
            fallbackCopyTextToClipboard(text);
        }
    };

    function fallbackCopyTextToClipboard(text) {
        var tempInput = document.createElement("input");
        tempInput.value = text;
        document.body.appendChild(tempInput);
        tempInput.select();
        try {
            document.execCommand("copy");
            alert(wdcv_admin_vars.copied_text + " " + text);
        } catch (err) {
            console.error('Fallback copy failed: ', err);
            alert(wdcv_admin_vars.copy_failed_text + " " + text);
        }
        document.body.removeChild(tempInput);
    }

    window.showCardOrdersFromData = function(button) {
        var orders = JSON.parse(button.getAttribute('data-orders'));
        var cardLabel = button.getAttribute('data-card-label');
        showCardOrders(orders, cardLabel);
    };

    function showCardOrders(orders, cardLabel) {
        currentModalOrders = orders || [];
        currentCardLabel = cardLabel || "";
        currentModalPage = 1;

        var modal = document.getElementById("orderModal");
        if (!modal) {
            alert('Modal element not found!');
            return;
        }

        modal.style.display = "block";
        document.body.style.overflow = "hidden";
        renderModalPage();
    }

    function renderModalPage() {
        var tbody = document.getElementById("orderTableBody");
        var title = document.getElementById("modalTitle");
        var pagination = document.getElementById("modalPagination");

        title.textContent = wdcv_admin_vars.successful_transactions_text + " " + currentCardLabel;
        tbody.innerHTML = "";
        pagination.innerHTML = "";

        if (currentModalOrders.length === 0) {
            tbody.innerHTML = "<tr><td colspan='4' style='text-align:center;'>" + wdcv_admin_vars.no_transactions_text + "</td></tr>";
            return;
        }

        var totalPages = Math.ceil(currentModalOrders.length / itemsPerPage);
        var start = (currentModalPage - 1) * itemsPerPage;
        var end = start + itemsPerPage;
        var pageItems = currentModalOrders.slice(start, end);

        pageItems.forEach(function(order) {
            var row = document.createElement("tr");
            var editUrl = wdcv_admin_vars.admin_url + 'post.php?post=' + order.id + '&action=edit';

            row.innerHTML =
                "<td>#" + order.id + "</td>" +
                "<td>" + new Intl.NumberFormat('fa-IR').format(order.amount) + "</td>" +
                "<td>" + (order.date || '---') + "</td>" +
                "<td><a href='" + editUrl + "' class='button button-small' target='_blank'>📎 " + wdcv_admin_vars.details_text + "</a></td>";
            tbody.appendChild(row);
        });

        if (totalPages > 1) {
            for (var i = 1; i <= totalPages; i++) {
                (function(p) {
                    var btn = document.createElement("button");
                    btn.textContent = new Intl.NumberFormat('fa-IR').format(p);
                    btn.className = "wdcv-page-btn" + (p === currentModalPage ? " active" : "");
                    btn.onclick = function() {
                        currentModalPage = p;
                        renderModalPage();
                    };
                    pagination.appendChild(btn);
                })(i);
            }
        }
    }

    window.closeModal = function() {
        var modal = document.getElementById("orderModal");
        if (modal) {
            modal.style.display = "none";
        }
        document.body.style.overflow = "auto";
    };

    function openGuide(guideId) {
        var modal = document.getElementById('wdcv-guide-' + guideId);
        if (!modal) {
            return;
        }
        modal.hidden = false;
        modal.style.display = 'block';
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeGuideModals() {
        document.querySelectorAll('.wdcv-guide-modal').forEach(function(modal) {
            modal.hidden = true;
            modal.style.display = 'none';
            modal.classList.remove('is-open');
        });
        var orderModal = document.getElementById('orderModal');
        if (!orderModal || orderModal.style.display !== 'block') {
            document.body.style.overflow = 'auto';
        }
    }

    window.onclick = function(event) {
        var orderModal = document.getElementById("orderModal");
        if (orderModal && event.target === orderModal) {
            closeModal();
        }
        if (event.target.classList && event.target.classList.contains('wdcv-guide-modal')) {
            closeGuideModals();
        }
    };

    $(document).ready(function() {
        $(document).on('click', '[data-orders]', function(e) {
            e.preventDefault();
            window.showCardOrdersFromData(this);
        });

        $(document).on('click', '.wdcv-modal-close', function() {
            window.closeModal();
        });

        $(document).on('click', '.wdcv-help-btn', function(e) {
            e.preventDefault();
            openGuide($(this).data('wdcv-guide'));
        });

        $(document).on('click', '.wdcv-guide-close', function(e) {
            e.preventDefault();
            closeGuideModals();
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                closeGuideModals();
                window.closeModal();
            }
        });
    });

})(jQuery);
