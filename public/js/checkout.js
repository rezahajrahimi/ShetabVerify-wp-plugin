/**
 * WebDide Card-to-Card Verification — Payment page script
 * All dynamic data is provided via wp_localize_script as `wdcvCheckoutVars`.
 */
(function () {
    'use strict';

    if ( typeof wdcvCheckoutVars === 'undefined' ) {
        return;
    }

    var remaining  = parseInt( wdcvCheckoutVars.remaining, 10 );
    var txnId      = parseInt( wdcvCheckoutVars.txnId, 10 );
    var orderId    = parseInt( wdcvCheckoutVars.orderId, 10 );
    var orderKey   = wdcvCheckoutVars.orderKey;
    var statusUrl  = wdcvCheckoutVars.statusUrl;
    var uploadUrl  = wdcvCheckoutVars.uploadUrl;
    var returnUrl  = wdcvCheckoutVars.returnUrl || '';
    var shouldPoll = !!wdcvCheckoutVars.shouldPoll;
    var pollTimer  = null;

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

    function copyText( text, feedbackEl ) {
        var value = String( text || '' );
        if ( ! value ) {
            return;
        }

        var done = function () {
            if ( ! feedbackEl ) {
                return;
            }
            var original = feedbackEl.getAttribute( 'data-original-label' );
            if ( ! original ) {
                original = feedbackEl.textContent;
                feedbackEl.setAttribute( 'data-original-label', original );
            }
            feedbackEl.textContent = wdcvCheckoutVars.copiedText || 'Copied';
            feedbackEl.classList.add( 'is-copied' );
            setTimeout( function () {
                feedbackEl.textContent = original;
                feedbackEl.classList.remove( 'is-copied' );
            }, 1600 );
        };

        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( value ).then( done ).catch( function () {
                fallbackCopy( value, done );
            } );
        } else {
            fallbackCopy( value, done );
        }
    }

    function fallbackCopy( text, onSuccess ) {
        var ta = document.createElement( 'textarea' );
        ta.value = text;
        ta.setAttribute( 'readonly', '' );
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        document.body.appendChild( ta );
        ta.select();
        try {
            document.execCommand( 'copy' );
            onSuccess();
        } catch ( err ) {
            alert( ( wdcvCheckoutVars.copyFailedText || 'Copy failed:' ) + ' ' + text );
        }
        document.body.removeChild( ta );
    }

    // ── Copy amount / card ─────────────────────────────────────────────────
    var amountBtn = document.getElementById( 'shetab-copy-amount' );
    if ( amountBtn ) {
        amountBtn.addEventListener( 'click', function () {
            var hint = amountBtn.querySelector( '.shetab-amount-hint' );
            copyText( amountBtn.getAttribute( 'data-copy' ), hint || amountBtn );
        } );
    }

    var copyBtns = document.querySelectorAll( '.shetab-copy-btn, #shetab-copy-card, #shetab-copy-account, #shetab-copy-sheba' );
    for ( var c = 0; c < copyBtns.length; c++ ) {
        (function ( btn ) {
            if ( btn.getAttribute( 'data-copy-bound' ) === '1' ) {
                return;
            }
            btn.setAttribute( 'data-copy-bound', '1' );
            btn.addEventListener( 'click', function () {
                copyText( btn.getAttribute( 'data-copy' ), btn );
            } );
        })( copyBtns[ c ] );
    }

    // ── Receipt Upload ──────────────────────────────────────────────────────
    var fileInput    = document.getElementById( 'shetab-receipt-files' );
    var pickBtn      = document.getElementById( 'shetab-pick-receipt' );
    var uploadBtn    = document.getElementById( 'shetab-do-upload' );
    var previewBlock = document.getElementById( 'file-list-preview' );

    if ( fileInput && uploadBtn && previewBlock ) {
        if ( pickBtn ) {
            pickBtn.onclick = function () {
                fileInput.click();
            };
        }

        fileInput.onchange = function () {
            previewBlock.innerHTML = '';
            if ( this.files.length > 0 ) {
                uploadBtn.classList.remove( 'is-hidden' );
                for ( var i = 0; i < this.files.length; i++ ) {
                    var img = document.createElement( 'img' );
                    img.src = URL.createObjectURL( this.files[ i ] );
                    previewBlock.appendChild( img );
                }
            } else {
                uploadBtn.classList.add( 'is-hidden' );
            }
        };

        uploadBtn.onclick = function () {
            var formData = new FormData();
            formData.append( 'order_id', orderId );
            formData.append( 'order_key', orderKey || '' );
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

    // ── Status Polling (every 5 s) — only while pending ───────────────────
    if ( shouldPoll && statusUrl && orderId ) {
        pollTimer = setInterval( function () {
            fetch( statusUrl + '?order_id=' + orderId + '&order_key=' + encodeURIComponent( orderKey || '' ) )
                .then( function ( r ) { return r.json(); } )
                .then( function ( data ) {
                    if ( data && data.status === 'confirmed' ) {
                        if ( pollTimer ) {
                            clearInterval( pollTimer );
                            pollTimer = null;
                        }
                        if ( returnUrl ) {
                            window.location.replace( returnUrl );
                        } else {
                            window.location.reload();
                        }
                    }
                } )
                .catch( function ( err ) {
                    console.error( 'Error polling status:', err );
                } );
        }, 5000 );
    }

})();
