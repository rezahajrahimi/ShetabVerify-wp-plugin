( function () {
    'use strict';

    if ( typeof window.shetabVerifyInit !== 'undefined' ) {
        return;
    }
    window.shetabVerifyInit = true;

    var config = window.shetab_verify || {};

    // Register with WooCommerce Blocks
    if ( window.wc && window.wc.wcBlocksRegistry && typeof window.wc.wcBlocksRegistry.registerPaymentMethod === 'function' ) {
        const settings = window.wc.wcSettings.getSetting( 'shetab_verify_data', {} );
        console.log('ShetabVerify Blocks Settings:', settings);

        const label = window.wp.htmlEntities.decodeEntities( settings.title || 'ShetabVerify' );

        const Content = ( props ) => {
            const { eventRegistration, emitResponse } = props;
            const { onPaymentSetup } = eventRegistration;

            window.wp.element.useEffect( () => {
                const unsubscribe = onPaymentSetup( () => {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                'payment_method': 'shetab_verify',
                            },
                        },
                    };
                } );
                return unsubscribe;
            }, [ emitResponse.responseTypes.SUCCESS, onPaymentSetup ] );

            return window.wp.element.createElement( 'div', { className: 'shetab-verify-blocks' },
                window.wp.element.createElement( 'p', null, window.wp.htmlEntities.decodeEntities( settings.description || 'Pay via bank transfer with unique suffix amount.' ) )
            );
        };

        const Label = ( props ) => {
            const { PaymentMethodLabel } = props.components;
            return window.wp.element.createElement( PaymentMethodLabel, { text: label } );
        };

        window.wc.wcBlocksRegistry.registerPaymentMethod( {
            name: 'shetab_verify',
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

    // Fallback: observe DOM to show console when checkout payment option is present
    function observePayments() {
        var selector = '#payment, .wc-block-components-checkout__payment-methods';
        var container = document.querySelector( selector );
        if ( ! container ) {
            return;
        }

        var radio = container.querySelector( 'input[value="shetab_verify"]' );
        if ( radio ) {
            // nothing to do — server-side gateway will handle process_payment
            console.debug( 'ShetabVerify: payment method detected on page' );
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
