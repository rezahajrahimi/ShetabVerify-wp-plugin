/**
 * WebDide Card-to-Card Verification — Checkout / Thank-You Page Script
 * All dynamic data is provided via wp_localize_script as `wdcvCheckoutVars`.
 */
(function () {
    'use strict';

    if ( typeof wdcvCheckoutVars === 'undefined' ) {
        return;
    }

    var remaining  = parseInt( wdcvCheckoutVars.remaining,  10 );
    var txnId      = parseInt( wdcvCheckoutVars.txnId,      10 );
    var orderId    = parseInt( wdcvCheckoutVars.orderId,    10 );
    var statusUrl  = wdcvCheckoutVars.statusUrl;
    var uploadUrl  = wdcvCheckoutVars.uploadUrl;

    // ── Countdown Timer ────────────────────────────────────────────────────
    var el = document.getElementById( 'shetab-countdown-' + txnId );
    if ( el ) {
        var timer = setInterval( function () {
            if ( remaining <= 0 ) {
                el.textContent = wdcvCheckoutVars.expiredText;
                clearInterval( timer );
                return;
            }
            remaining--;
            var mm = Math.floor( remaining / 60 );
            var ss = remaining % 60;
            el.textContent = wdcvCheckoutVars.timerText + ' ' +
                ( mm < 10 ? ( '0' + mm ) : mm ) + ':' +
                ( ss < 10 ? ( '0' + ss ) : ss );
        }, 1000 );
    }

    // ── Receipt Upload ──────────────────────────────────────────────────────
    var fileInput   = document.getElementById( 'shetab-receipt-files' );
    var uploadBtn   = document.getElementById( 'shetab-do-upload' );
    var previewBlock = document.getElementById( 'file-list-preview' );

    if ( fileInput && uploadBtn && previewBlock ) {
        fileInput.onchange = function () {
            previewBlock.innerHTML = '';
            if ( this.files.length > 0 ) {
                uploadBtn.style.display = 'inline-block';
                for ( var i = 0; i < this.files.length; i++ ) {
                    var img = document.createElement( 'img' );
                    img.src = URL.createObjectURL( this.files[ i ] );
                    previewBlock.appendChild( img );
                }
            } else {
                uploadBtn.style.display = 'none';
            }
        };

        uploadBtn.onclick = function () {
            var formData = new FormData();
            formData.append( 'order_id', orderId );
            for ( var j = 0; j < fileInput.files.length; j++ ) {
                formData.append( 'receipts[]', fileInput.files[ j ] );
            }

            this.disabled    = true;
            this.textContent = wdcvCheckoutVars.uploadingText;

            fetch( uploadUrl, { method: 'POST', body: formData } )
                .then( function ( r ) { return r.json(); } )
                .then( function ( data ) {
                    if ( data.success ) {
                        alert( data.message );
                        window.location.reload();
                    } else {
                        alert( wdcvCheckoutVars.errorText + ' ' +
                            ( data.message || wdcvCheckoutVars.uploadErrorText ) );
                        uploadBtn.disabled    = false;
                        uploadBtn.textContent = wdcvCheckoutVars.sendText;
                    }
                } )
                .catch( function ( err ) {
                    console.error( err );
                    alert( wdcvCheckoutVars.systemErrorText );
                    uploadBtn.disabled    = false;
                    uploadBtn.textContent = wdcvCheckoutVars.sendText;
                } );
        };
    }

    // ── Status Polling (every 5 s) ─────────────────────────────────────────
    setInterval( function () {
        fetch( statusUrl + '?order_id=' + orderId )
            .then( function ( r ) { return r.json(); } )
            .then( function ( data ) {
                if ( data && data.status === 'confirmed' ) {
                    window.location.reload();
                }
            } )
            .catch( function ( err ) {
                console.error( 'Error polling status:', err );
            } );
    }, 5000 );

})();
