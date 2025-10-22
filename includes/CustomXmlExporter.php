<?php
namespace WPO\IPS;

use DOMDocument;
use WC_Abstract_Order;
use WPO\IPS\Documents\BulkDocument;
use WPO\IPS\Documents\OrderDocument;

if ( ! defined( 'ABSPATH' ) ) {
        exit; // Exit if accessed directly
}

if ( ! class_exists( '\\WPO\\IPS\\CustomXmlExporter' ) ) :

class CustomXmlExporter {

        /**
         * Singleton instance.
         *
         * @var self|null
         */
        protected static ?self $instance = null;

        /**
         * Retrieve singleton instance.
         */
        public static function instance(): self {
                if ( is_null( self::$instance ) ) {
                        self::$instance = new self();
                }

                return self::$instance;
        }

        /**
         * Register hooks.
         */
        protected function __construct() {
                add_filter( 'wpo_wcpdf_document_output_formats', array( $this, 'register_invoice_xml_output_format' ), 20, 2 );
                add_filter( 'wpo_wcpdf_document_is_enabled', array( $this, 'force_invoice_xml_enabled' ), 10, 3 );
        }

        /**
         * Ensure invoices advertise XML as an available output format.
         */
        public function register_invoice_xml_output_format( array $formats, $document ): array {
                $document_type = is_object( $document ) && is_callable( array( $document, 'get_type' ) ) ? $document->get_type() : '';

                if ( 'invoice' === $document_type && ! in_array( 'xml', $formats, true ) ) {
                        $formats[] = 'xml';
                }

                return $formats;
        }

        /**
         * Make sure invoice XML output is considered enabled.
         */
        public function force_invoice_xml_enabled( $enabled, string $document_type, string $output_format ) {
                if ( 'invoice' === $document_type && 'xml' === $output_format ) {
                        return true;
                }

                return $enabled;
        }

        /**
         * Stream XML for a single invoice document.
         */
        public function output_document_xml( OrderDocument $document ): void {
                $xml = $this->get_document_xml_contents( $document );

                if ( '' === $xml ) {
                        wp_die( esc_html__( 'The invoice XML file could not be generated.', 'woocommerce-pdf-invoices-packing-slips' ) );
                }

                $filename = $document->get_filename( 'download', array( 'output' => 'xml' ) );

                $this->stream_xml_contents( $xml, $filename );
        }

        /**
         * Stream XML for a bulk invoice document.
         */
        public function output_bulk_document_xml( BulkDocument $bulk_document ): void {
                if ( 'invoice' !== $bulk_document->get_type() ) {
                        wp_die( esc_html__( 'Invoice XML export is only available for invoices.', 'woocommerce-pdf-invoices-packing-slips' ) );
                }

                $xml = $this->get_bulk_document_xml_contents( $bulk_document );

                if ( '' === $xml ) {
                        wp_die( esc_html__( 'The invoice XML file could not be generated.', 'woocommerce-pdf-invoices-packing-slips' ) );
                }

                $filename = $bulk_document->get_filename( 'download', array( 'output' => 'xml' ) );

                $this->stream_xml_contents( $xml, $filename );
        }

        /**
         * Generate XML contents for a single document without streaming.
         */
        public function get_document_xml_contents( OrderDocument $document ): string {
                if ( 'invoice' !== $document->get_type() ) {
                        return '';
                }

                $order = $this->resolve_order_from_document( $document );

                if ( ! $order ) {
                        return '';
                }

                $data = $this->prepare_invoice_data( $document, $order );

                if ( empty( $data ) ) {
                        return '';
                }

                $data = apply_filters( 'wpo_wcpdf_invoice_xml_export_data', $data, $document, $order );

                if ( empty( $data ) ) {
                        return '';
                }

                $xml = $this->render_xml_document( array( $data ) );

                return apply_filters( 'wpo_wcpdf_invoice_xml_export_xml', $xml, $data, $document, $order );
        }

        /**
         * Generate XML contents for a bulk document without streaming.
         */
        public function get_bulk_document_xml_contents( BulkDocument $bulk_document ): string {
                $order_ids = property_exists( $bulk_document, 'order_ids' ) ? (array) $bulk_document->order_ids : array();

                if ( empty( $order_ids ) ) {
                        return '';
                }

                $invoices = array();

                foreach ( $order_ids as $order_id ) {
                        $order = wc_get_order( $order_id );

                        if ( ! $order instanceof WC_Abstract_Order ) {
                                continue;
                        }

                        $document = wcpdf_get_document( $bulk_document->get_type(), $order, true );

                        if ( ! $document instanceof OrderDocument ) {
                                continue;
                        }

                        $data = $this->prepare_invoice_data( $document, $order );

                        if ( empty( $data ) ) {
                                continue;
                        }

                        $data = apply_filters( 'wpo_wcpdf_invoice_xml_export_data', $data, $document, $order );

                        if ( empty( $data ) ) {
                                continue;
                        }

                        $invoices[] = $data;
                }

                if ( empty( $invoices ) ) {
                        return '';
                }

                $xml = $this->render_xml_document( $invoices );

                return apply_filters( 'wpo_wcpdf_invoice_xml_export_bulk_xml', $xml, $invoices, $bulk_document );
        }

        /**
         * Persist XML contents for a document to the provided path.
         */
        public function write_document_xml_file( OrderDocument $document, string $path ): bool {
                $xml = $this->get_document_xml_contents( $document );

                if ( '' === $xml ) {
                        return false;
                }

                $xml = $this->normalize_xml_output( $xml );

                if ( '' === $xml ) {
                        return false;
                }

                $filesystem = WPO_WCPDF()->file_system;

                $directory = dirname( $path );

                if ( ! $filesystem->is_dir( $directory ) && ! $filesystem->mkdir( $directory ) ) {
                        return false;
                }

                return (bool) $filesystem->put_contents( $path, $xml );
        }

        /**
         * Resolve the WooCommerce order attached to the document.
         */
        protected function resolve_order_from_document( OrderDocument $document ): ?WC_Abstract_Order {
                if ( $document->order instanceof WC_Abstract_Order ) {
                        return $document->order;
                }

                if ( ! empty( $document->order_id ) ) {
                        $order = wc_get_order( $document->order_id );

                        if ( $order instanceof WC_Abstract_Order ) {
                                return $order;
                        }
                }

                return null;
        }

        /**
         * Stream XML contents to the browser.
         */
        protected function stream_xml_contents( string $xml, string $filename ): void {
                $xml = $this->normalize_xml_output( $xml );

                if ( '' === $xml ) {
                        wp_die( esc_html__( 'The invoice XML file could not be generated.', 'woocommerce-pdf-invoices-packing-slips' ) );
                }

                while ( ob_get_level() > 0 ) {
                        ob_end_clean();
                }

                header( 'Content-Type: application/xml; charset=utf-8' );
                header( sprintf( 'Content-Disposition: attachment; filename="%s"', addcslashes( $filename, "\\\"" ) ) );
                header( 'Content-Length: ' . strlen( $xml ) );

                echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                exit;
        }

        /**
         * Build the XML document for the provided invoices.
         */
        protected function render_xml_document( array $invoices ): string {
                $dom              = new DOMDocument( '1.0', 'UTF-8' );
                $dom->formatOutput = true;

                $root = $dom->createElement( 'Pohladavky' );
                $root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance' );
                $root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:xsd', 'http://www.w3.org/2001/XMLSchema' );
                $dom->appendChild( $root );

                $list_node = $dom->createElement( 'ZoznamPohladavok' );
                $root->appendChild( $list_node );

                foreach ( $invoices as $invoice ) {
                        if ( ! is_array( $invoice ) ) {
                                continue;
                        }

                        $invoice_node = $this->create_invoice_node( $dom, $invoice );

                        if ( $invoice_node ) {
                                $list_node->appendChild( $invoice_node );
                        }
                }

                if ( 0 === $list_node->childNodes->length ) {
                        return '';
                }

                return $dom->saveXML() ?: '';
        }

        /**
         * Create a DOM node representing a single invoice.
         */
        protected function create_invoice_node( DOMDocument $dom, array $data ) {
                if ( empty( $data ) ) {
                        return null;
                }

                $invoice_node = $dom->createElement( 'Pohladavka' );

                foreach ( $data as $key => $value ) {
                        if ( 'Partner' === $key && is_array( $value ) ) {
                                $partner_node = $dom->createElement( 'Partner' );

                                foreach ( $value as $partner_key => $partner_value ) {
                                        $this->append_node_with_value( $dom, $partner_node, $partner_key, $partner_value );
                                }

                                if ( $partner_node->hasChildNodes() ) {
                                        $invoice_node->appendChild( $partner_node );
                                }

                                continue;
                        }

                        $this->append_node_with_value( $dom, $invoice_node, $key, $value );
                }

                return $invoice_node;
        }

        /**
         * Append a node with value to the parent element.
         */
        protected function append_node_with_value( DOMDocument $dom, \DOMElement $parent, string $name, $value ): void {
                $child = $dom->createElement( $name );
                $child->appendChild( $dom->createTextNode( (string) $value ) );
                $parent->appendChild( $child );
        }

        /**
         * Prepare data structure used for XML export.
         */
        protected function prepare_invoice_data( OrderDocument $document, WC_Abstract_Order $order ): array {
                $data = $this->collect_invoice_data( $document, $order );

                if ( empty( $data ) ) {
                        return array();
                }

                return array_map( array( $this, 'stringify_value' ), $data );
        }

        /**
         * Collect invoice data before type normalization.
         */
        protected function collect_invoice_data( OrderDocument $document, WC_Abstract_Order $order ): array {
                $invoice_number  = $this->resolve_invoice_number( $document );
                $variable_symbol = $this->resolve_variable_symbol( $invoice_number );
                $issue_date      = $this->format_date_value( $document->get_date( '', null, 'view', false ), time() );
                $due_date        = $this->format_due_date( $document, $issue_date );
                $tax_date        = $this->format_tax_date( $document, $order, $issue_date );
                $partner_name    = $this->resolve_partner_name( $order );
                $ico             = $this->get_order_meta_value( $order, array( '_billing_ico', 'billing_ico', '_customer_ico' ) );
                $dic             = $this->get_order_meta_value( $order, array( '_billing_dic', 'billing_dic', '_customer_dic' ) );
                $ic_dph          = $this->get_order_meta_value( $order, array( '_billing_ic_dph', 'billing_ic_dph', '_customer_vat', '_billing_vat', '_billing_vat_number', '_billing_tax_id' ) );

                if ( '' === $ic_dph && function_exists( 'wpo_wcpdf_get_order_customer_vat_number' ) ) {
                        $vat_number = wpo_wcpdf_get_order_customer_vat_number( $order );

                        if ( ! empty( $vat_number ) ) {
                                $ic_dph = $vat_number;
                        }
                }

                $ico    = '' !== $ico ? $ico : '0';
                $dic    = '' !== $dic ? $dic : '0';
                $ic_dph = '' !== $ic_dph ? $ic_dph : '0';

                $subject = $this->resolve_subject( $document, $order );
                $total   = wc_format_decimal( $order->get_total(), 2 );

                if ( '' === $total ) {
                        $total = '0';
                }

                return array(
                        'InterneCislo'      => $invoice_number,
                        'VariabilnySymbol'  => $variable_symbol,
                        'Partner'           => array(
                                'NazovPartnera' => $partner_name,
                                'ICO'           => $ico,
                                'DIC'           => $dic,
                                'IC_DPH'        => $ic_dph,
                        ),
                        'Vyhotovene'        => $issue_date,
                        'Splatnost'         => $due_date,
                        'DVDP'              => $tax_date,
                        'PredmetFakturacie' => $subject,
                        'SumaNevstupuje'    => $total,
                        'KurzPohladavky'    => '1',
                        'KurzDPH'           => '1',
                        'MenaDokladu'       => $order->get_currency(),
                );
        }

        /**
         * Convert nested values to strings.
         */
        protected function stringify_value( $value ) {
                if ( is_array( $value ) ) {
                        foreach ( $value as $key => $child ) {
                                $value[ $key ] = $this->stringify_value( $child );
                        }

                        return $value;
                }

                if ( is_bool( $value ) ) {
                        return $value ? '1' : '0';
                }

                if ( is_scalar( $value ) ) {
                        return (string) $value;
                }

                return '';
        }

        /**
         * Ensure XML starts with a declaration and is free of leading BOM/whitespace.
         */
        protected function normalize_xml_output( $xml ): string {
                if ( ! is_string( $xml ) ) {
                        return '';
                }

                if ( 0 === strpos( $xml, "\xEF\xBB\xBF" ) ) {
                        $xml = substr( $xml, 3 );
                }

                if ( '' === $xml ) {
                        return '';
                }

                $first_tag_position = strpos( $xml, '<' );

                if ( false === $first_tag_position ) {
                        return '';
                }

                if ( $first_tag_position > 0 ) {
                        $xml = substr( $xml, $first_tag_position );
                }

                if ( '' === $xml ) {
                        return '';
                }

                $xml = ltrim( $xml, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20" );

                if ( '' === $xml ) {
                        return '';
                }

                if ( 0 === strpos( $xml, '<?xml' ) ) {
                        $xml = preg_replace_callback(
                                '/^<\?xml[^>]*\?>\s*/u',
                                static function ( $matches ) {
                                        return rtrim( $matches[0] ) . "\n";
                                },
                                $xml,
                                1
                        );

                        if ( ! is_string( $xml ) ) {
                                return '';
                        }

                        return $xml;
                }

                return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml;
        }

        /**
         * Resolve formatted invoice number.
         */
        protected function resolve_invoice_number( OrderDocument $document ): string {
                $invoice_number = $document->get_number( '', null, 'view', true );
                $invoice_number = is_string( $invoice_number ) ? trim( $invoice_number ) : '';

                if ( '' === $invoice_number ) {
                        $raw_number = $document->get_number();
                        if ( is_object( $raw_number ) && is_callable( array( $raw_number, 'get_formatted' ) ) ) {
                                $invoice_number = trim( (string) $raw_number->get_formatted() );
                        }
                }

                return $invoice_number;
        }

        /**
         * Convert invoice number into a numeric variable symbol.
         */
        protected function resolve_variable_symbol( string $invoice_number ): string {
                $variable_symbol = preg_replace( '/\D+/', '', $invoice_number );

                if ( '' === $variable_symbol ) {
                        $variable_symbol = $invoice_number;
                }

                return $variable_symbol;
        }

        /**
         * Format date values as YYYY-MM-DD.
         */
        protected function format_date_value( $value, int $fallback_timestamp ): string {
                if ( $value instanceof \WC_DateTime ) {
                        return $value->date( 'Y-m-d' );
                }

                if ( $value instanceof \DateTimeInterface ) {
                        return $value->format( 'Y-m-d' );
                }

                if ( is_numeric( $value ) ) {
                        return wp_date( 'Y-m-d', (int) $value );
                }

                if ( is_string( $value ) && '' !== $value ) {
                        $timestamp = strtotime( $value );
                        if ( false !== $timestamp ) {
                                return wp_date( 'Y-m-d', $timestamp );
                        }
                }

                return wp_date( 'Y-m-d', $fallback_timestamp );
        }

        /**
         * Format due date using document configuration.
         */
        protected function format_due_date( OrderDocument $document, string $fallback ): string {
                $timestamp = $document->get_due_date();

                if ( $timestamp > 0 ) {
                        return wp_date( 'Y-m-d', $timestamp );
                }

                return $fallback;
        }

        /**
         * Determine tax date based on document settings.
         */
        protected function format_tax_date( OrderDocument $document, WC_Abstract_Order $order, string $fallback ): string {
                $display_date_setting = $document->get_display_date();

                if ( 'order_date' === $display_date_setting ) {
                        $order_date = $order->get_date_created();
                        if ( $order_date instanceof \WC_DateTime ) {
                                return $order_date->date( 'Y-m-d' );
                        }
                        if ( $order_date instanceof \DateTimeInterface ) {
                                return $order_date->format( 'Y-m-d' );
                        }
                }

                return $fallback;
        }

        /**
         * Determine the partner name from the order data.
         */
        protected function resolve_partner_name( WC_Abstract_Order $order ): string {
                $partner_name = $order->get_billing_company();

                if ( empty( $partner_name ) ) {
                        $partner_name = trim( $order->get_formatted_billing_full_name() );
                }

                if ( empty( $partner_name ) ) {
                        $partner_name = trim( $order->get_formatted_shipping_full_name() );
                }

                if ( empty( $partner_name ) ) {
                        $partner_name = __( 'Customer', 'woocommerce-pdf-invoices-packing-slips' );
                }

                return wc_clean( $partner_name );
        }

        /**
         * Fetch the first non-empty meta value from the provided keys.
         */
        protected function get_order_meta_value( WC_Abstract_Order $order, array $keys ): string {
                foreach ( $keys as $key ) {
                        $value = $order->get_meta( $key );
                        if ( ! empty( $value ) ) {
                                return wc_clean( (string) $value );
                        }
                }

                return '';
        }

        /**
         * Build the invoice subject using purchased items.
         */
        protected function resolve_subject( OrderDocument $document, WC_Abstract_Order $order ): string {
                $items = $document->get_order_items();
                $names = array();

                if ( ! empty( $items ) && is_array( $items ) ) {
                        foreach ( $items as $item ) {
                                if ( isset( $item['name'] ) && '' !== $item['name'] ) {
                                        $names[] = wc_clean( wp_strip_all_tags( (string) $item['name'] ) );
                                }
                        }
                }

                $names = array_filter( array_unique( $names ) );

                if ( ! empty( $names ) ) {
                        return implode( ', ', $names );
                }

                return sprintf(
                        /* translators: %s: order number */
                        __( 'Order %s', 'woocommerce-pdf-invoices-packing-slips' ),
                        $order->get_order_number()
                );
        }
}

endif;
