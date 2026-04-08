/**
 * WebDide Card-to-Card Verification Admin JavaScript
 * Handles modal functionality and clipboard operations
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
    }

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
        document.body.style.overflow = "hidden"; // Prevent background scroll

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

        // Pagination logic
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

        // Render pagination buttons if more than one page
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
        document.getElementById("orderModal").style.display = "none";
        document.body.style.overflow = "auto";
    };

    // Close on outside click
    window.onclick = function(event) {
        var modal = document.getElementById("orderModal");
        if (event.target == modal) {
            closeModal();
        }
    }

    // Initialize when document is ready
    $(document).ready(function() {
        // Bind click events for buttons with data-orders attribute
        $(document).on('click', '[data-orders]', function(e) {
            e.preventDefault();
            window.showCardOrdersFromData(this);
        });

        // Bind close modal events
        $(document).on('click', '.wdcv-modal-close', function() {
            window.closeModal();
        });
    });

})(jQuery);