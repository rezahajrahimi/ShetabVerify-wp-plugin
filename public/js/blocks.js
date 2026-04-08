( function () {
    'use strict';

    if ( typeof window.WebDide_CVInit !== 'undefined' ) {
        return;
    }
    window.WebDide_CVInit = true;

    // Register with WooCommerce Blocks
    if ( window.wc && window.wc.wcBlocksRegistry && typeof window.wc.wcBlocksRegistry.registerPaymentMethod === 'function' ) {
        const settings = window.wc.wcSettings.getSetting( 'wdcv_data', {} );

        const label = window.wp.htmlEntities.decodeEntities( settings.title || 'کارت به کارت' );

        const Content = ( props ) => {
            const { eventRegistration, emitResponse } = props;
            const { onPaymentSetup } = eventRegistration;

            window.wp.element.useEffect( () => {
                const unsubscribe = onPaymentSetup( () => {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                'payment_method': 'wdcv',
                            },
                        },
                    };
                } );
                return unsubscribe;
            }, [ emitResponse.responseTypes.SUCCESS, onPaymentSetup ] );

            return window.wp.element.createElement( 'div', { className: 'wdcv-blocks' },
                window.wp.element.createElement( 'p', null, window.wp.htmlEntities.decodeEntities( settings.description || 'تایید خودکار کارت به کارت' ) )
            );
        };

        const Label = ( props ) => {
            const { PaymentMethodLabel } = props.components;
            const labelText = window.wp.htmlEntities.decodeEntities( settings.title || 'کارت به کارت' );
            if ( settings.logo_url ) {
                return window.wp.element.createElement( 'div', { style: { display: 'flex', alignItems: 'center', gap: '10px' } },
                    window.wp.element.createElement( 'img', { src: settings.logo_url, alt: labelText, style: { height: '32px', width: 'auto' } } ),
                    window.wp.element.createElement( PaymentMethodLabel, { text: labelText } )
                );
            }
            return window.wp.element.createElement( PaymentMethodLabel, { text: labelText } );
        };

        window.wc.wcBlocksRegistry.registerPaymentMethod( {
            name: 'wdcv',
            label: window.wp.element.createElement( Label ),
            content: window.wp.element.createElement( Content ),
            edit: window.wp.element.createElement( Content ),
            canMakePayment: () => true,
            ariaLabel: label,
            supports: {
                features: settings.supports || [ 'products' ],
            },
        } );
    }

    // Fallback: observe DOM when checkout payment option is present
    function observePayments() {
        var selector = '#payment, .wc-block-components-checkout__payment-methods';
        var container = document.querySelector( selector );
        if ( ! container ) {
            return;
        }

        var radio = container.querySelector( 'input[value="wdcv"]' );
        if ( radio ) {
            return;
        }
    }

    document.addEventListener( 'DOMContentLoaded', function() {
        observePayments();
        var mo = new MutationObserver( observePayments );
        var parent = document.querySelector( 'body' );
        if ( parent ) {
            mo.observe( parent, { childList: true, subtree: true } );
        }
    } );

} )();

