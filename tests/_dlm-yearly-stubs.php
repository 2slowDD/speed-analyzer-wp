<?php
/**
 * Stand-ins for the parts of WooCommerce, WordPress and Digital License Manager
 * 2.0.2 (DLM) that the yearly-extension filter callback in
 * cloudflare/mu-plugins/wpsa-dlm-yearly.php relies on. They let
 * tests/dlm-yearly-extend-harness.php drive the real callback from the command
 * line the way DLM drives it on the shop. Run on its own, this file only
 * declares the stand-ins and exits 0.
 *
 * Each stand-in models one behaviour of the real code and names where it was
 * read:
 *   WC  = WooCommerce plugins/woocommerce/, trunk and the 11.1.0 tag (the same
 *         lines in both unless a line says otherwise);
 *   WP  = WordPress wp-includes/;
 *   DLM = digital-license-manager tags/2.0.2/includes/.
 *
 * One in-memory store stands in for the database. Objects are built from it and
 * write back to it the way WooCommerce's do, so an order loaded again (a replay)
 * sees exactly what was saved, and nothing that was only changed in memory.
 */

if ( 'cli' !== PHP_SAPI && ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The stand-in database, plus a log of side effects in the order they happened.
 */
final class DLMY_Store {
    /** @var array Order id => array( 'user_id' => int, 'items' => int[], 'meta' => array( key => stored value ) ). */
    public static $orders = array();
    /** @var array Item id => array( 'product_id' => int, 'quantity' => int, 'meta' => array( key => stored value ) ). */
    public static $items = array();
    /** @var array Order id => list of array( 'text' => string, 'customer' => 0 or 1 ). */
    public static $notes = array();
    /** @var string[] Side effects, oldest first. */
    public static $events = array();
    /** @var bool When true, saving an order line throws, as a failing save hook would. */
    public static $line_save_throws = false;

    public static function reset() {
        self::$orders           = array();
        self::$items            = array();
        self::$notes            = array();
        self::$events           = array();
        self::$line_save_throws = false;
    }

    /**
     * @param int   $order_id Order id.
     * @param int   $user_id  Customer's user id; 0 for a guest order.
     * @param array $lines    Item id => array( product id, quantity ).
     */
    public static function add_order( $order_id, $user_id, array $lines ) {
        self::$orders[ $order_id ] = array( 'user_id' => $user_id, 'items' => array_keys( $lines ), 'meta' => array() );
        foreach ( $lines as $item_id => $line ) {
            self::$items[ $item_id ] = array( 'product_id' => $line[0], 'quantity' => $line[1], 'meta' => array() );
        }
    }
}

/**
 * What the meta_value column holds after a write. WC_Data_Store_WP::add_meta()
 * and update_meta() (WC includes/data-stores/class-wc-data-store-wp.php:143-156)
 * go through WP add_metadata() / update_metadata_by_mid(), which store
 * maybe_serialize( $value ) (WP meta.php:106, :957; functions.php:636-651):
 * arrays and objects serialized, anything else as it is. The column is text, so
 * every value comes back from the database as a string.
 */
function dlmy_stored( $value ) {
    return ( is_array( $value ) || is_object( $value ) ) ? serialize( $value ) : (string) $value;
}

/**
 * What an object holds after a read. WC_Data::init_meta_data() runs
 * maybe_unserialize() on each raw value (WC includes/abstracts/abstract-wc-data.php:746;
 * WP functions.php:661-667), so only serialized data changes type; every other
 * value stays the string the database returned.
 */
function dlmy_loaded( $stored ) {
    if ( is_string( $stored ) && 1 === preg_match( '/^(?:a|O|s|i|d|b):/', $stored ) ) {
        $value = unserialize( $stored, array( 'allowed_classes' => false ) );
        if ( false !== $value || 'b:0;' === $stored ) {
            return $value;
        }
    }
    return $stored;
}

/**
 * WC WC_Data (includes/abstracts/abstract-wc-data.php), single-value meta only.
 */
abstract class WC_Data {
    /** @var int */
    protected $id = 0;
    /** @var array This object's own copy of its meta: key => value. */
    protected $meta_data = array();
    /** @var array Keys changed in memory and not yet written: key => true. */
    protected $changed_meta = array();

    public function get_id() {
        return $this->id;
    }

    /**
     * get_meta() :415-442: the value of the first entry with that key, or '' when
     * there is none (:426). It answers from the object's copy, never from storage.
     */
    public function get_meta( $key = '', $single = true, $context = 'view' ) {
        return array_key_exists( $key, $this->meta_data ) ? $this->meta_data[ $key ] : '';
    }

    /**
     * update_meta_data() :519-560: changes the object's copy only; nothing reaches
     * storage until the object is saved.
     */
    public function update_meta_data( $key, $value, $meta_id = 0 ) {
        $this->meta_data[ $key ]    = $value;
        $this->changed_meta[ $key ] = true;
    }

    /**
     * read_meta_data() :698-731 via init_meta_data() :738-750: a freshly built
     * object reads its meta rows from storage and unserializes each value.
     *
     * @param array $stored Stored meta: key => raw value.
     */
    protected function read_meta_data( array $stored ) {
        $this->meta_data    = array();
        $this->changed_meta = array();
        foreach ( $stored as $key => $raw ) {
            $this->meta_data[ $key ] = dlmy_loaded( $raw );
        }
    }

    /**
     * save_meta_data() :757-809: writes each changed entry (add_meta() :778, or
     * update_meta() :790-791) and leaves the rest alone.
     *
     * @param array $stored Stored meta before the write: key => raw value.
     * @return array Stored meta after the write.
     */
    protected function save_meta_data( array $stored ) {
        foreach ( array_keys( $this->changed_meta ) as $key ) {
            $stored[ $key ] = dlmy_stored( $this->meta_data[ $key ] );
        }
        $this->changed_meta = array();
        return $stored;
    }
}

/**
 * WC WC_Order_Item (includes/class-wc-order-item.php). Its meta lives in the
 * order-item meta table: the item data store's meta type is 'order_item'
 * (includes/data-stores/abstract-wc-order-item-type-data-store.php:30), which
 * WC_Data_Store_WP::get_db_info() turns into woocommerce_order_itemmeta
 * (class-wc-data-store-wp.php:164-193). Orders kept in the HPOS tables use the
 * same item tables: OrdersTableDataStore extends Abstract_WC_Order_Data_Store_CPT
 * (src/Internal/DataStores/Orders/OrdersTableDataStore.php:29) and inherits its
 * read_items() (includes/data-stores/abstract-wc-order-data-store-cpt.php:538-567).
 */
class WC_Order_Item extends WC_Data {
    /**
     * The item data store's read() (abstract-wc-order-item-type-data-store.php:156-179)
     * loads the line, then its meta (:179).
     *
     * @param int $item_id Item id.
     */
    public function __construct( $item_id = 0 ) {
        $this->id = (int) $item_id;
        $this->read_meta_data( DLMY_Store::$items[ $this->id ]['meta'] );
    }

    /**
     * The base class answers 1 (class-wc-order-item.php:178-180).
     */
    public function get_quantity() {
        return 1;
    }

    /**
     * Neither WC_Order_Item nor WC_Order_Item_Product overrides save(), so this is
     * WC_Data::save() (abstract-wc-data.php:284-301) -> the item data store's
     * update() (abstract-wc-order-item-type-data-store.php:102-128), which writes
     * the meta (save_meta_data(), :120) and clears the line's caches (:125;
     * clear_cache() :219-223), so the next load reads storage.
     */
    public function save() {
        DLMY_Store::$events[] = 'line saved';
        if ( DLMY_Store::$line_save_throws ) {
            throw new RuntimeException( 'a save hook failed' );
        }
        DLMY_Store::$items[ $this->id ]['meta'] = $this->save_meta_data( DLMY_Store::$items[ $this->id ]['meta'] );
        return $this->id;
    }
}

/**
 * WC WC_Order_Item_Product (includes/class-wc-order-item-product.php): the line
 * type DLM hands to the filter.
 */
class WC_Order_Item_Product extends WC_Order_Item {
    /** @var int */
    private $product_id;
    /** @var int */
    private $quantity;

    public function __construct( $item_id = 0 ) {
        parent::__construct( $item_id );
        $this->product_id = DLMY_Store::$items[ $this->id ]['product_id'];
        $this->quantity   = DLMY_Store::$items[ $this->id ]['quantity'];
    }

    /** get_product_id() :303-305. */
    public function get_product_id( $context = 'view' ) {
        return $this->product_id;
    }

    /** get_quantity() :323-325. */
    public function get_quantity( $context = 'view' ) {
        return $this->quantity;
    }

    /** get_product() :394-396: a product object from wc_get_product(), built afresh. */
    public function get_product() {
        return new WC_Product( $this->product_id );
    }
}

/**
 * WC WC_Product: the callback only reads its id.
 */
class WC_Product {
    /** @var int */
    private $id;

    public function __construct( $product_id ) {
        $this->id = (int) $product_id;
    }

    public function get_id() {
        return $this->id;
    }
}

/**
 * WC WC_Order (includes/class-wc-order.php, includes/abstracts/abstract-wc-order.php).
 */
class WC_Order extends WC_Data {
    /** @var int */
    private $user_id;
    /** @var WC_Order_Item_Product[]|null Lines, once read. */
    private $items = null;

    /**
     * @param int $order_id Order id; the order is built from storage.
     */
    public function __construct( $order_id = 0 ) {
        $this->id      = (int) $order_id;
        $this->user_id = DLMY_Store::$orders[ $this->id ]['user_id'];
        $this->read_meta_data( DLMY_Store::$orders[ $this->id ]['meta'] );
    }

    public function get_user_id() {
        return $this->user_id;
    }

    /**
     * get_items() (abstract-wc-order.php:1281-1305): the lines are read once, then
     * kept on the order (:1302), so every later call returns the same line objects.
     */
    public function get_items( $types = 'line_item' ) {
        if ( null === $this->items ) {
            $this->items = array();
            foreach ( DLMY_Store::$orders[ $this->id ]['items'] as $item_id ) {
                $this->items[ $item_id ] = new WC_Order_Item_Product( $item_id );
            }
        }
        return $this->items;
    }

    /**
     * add_order_note() (class-wc-order.php:2080-2126): the note is stored at once
     * (wp_insert_comment(), :2114) whether or not the order is ever saved; a customer
     * note is flagged (:2116-2117), which is what gets it e-mailed.
     */
    public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false, $meta_data = array() ) {
        $customer                         = $is_customer_note ? 1 : 0;
        DLMY_Store::$notes[ $this->id ][] = array( 'text' => $note, 'customer' => $customer );
        DLMY_Store::$events[]             = $customer ? 'customer note' : 'private note';
        return count( DLMY_Store::$notes[ $this->id ] );
    }

    /**
     * save() (abstract-wc-order.php:273-318): writes the order's own meta through its
     * data store, then saves every line already loaded on it (save_items(), :293,
     * :530-536). An \Exception from any of that is caught (:278, :303) and only
     * logged (handle_exception() :328-336), so it never reaches the caller.
     */
    public function save() {
        DLMY_Store::$events[] = 'order saved';
        try {
            DLMY_Store::$orders[ $this->id ]['meta'] = $this->save_meta_data( DLMY_Store::$orders[ $this->id ]['meta'] );
            foreach ( (array) $this->items as $item ) {
                $item->save();
            }
        } catch ( Exception $e ) {
            DLMY_Store::$events[] = 'order save failed';
        }
        return $this->id;
    }
}

/**
 * WC wc_get_order(), as DLM calls it on every run (DLM Integrations/WooCommerce/Orders.php:124):
 * an order built from storage. Across requests WooCommerce's order cache keeps
 * only the id of an order (WC_Data::__sleep(), abstract-wc-data.php:158-160) and
 * rebuilds it from storage (__wakeup(), :167-174).
 */
function wc_get_order( $order_id ) {
    return isset( DLMY_Store::$orders[ (int) $order_id ] ) ? new WC_Order( $order_id ) : false;
}

/**
 * A DLM licence as the repository returns it (DLM Database/Models/License.php).
 */
final class DLMY_License {
    /** @var array The row, as the database hands it back. */
    private $row;

    public function __construct( array $row ) {
        $this->row = $row;
    }

    // id, order_id and status are cast to int (License.php:61-70; getters :88-90, :96-97, :172-173).
    public function getId() {
        return (int) $this->row['id'];
    }

    public function getOrderId() {
        return (int) $this->row['order_id'];
    }

    public function getStatus() {
        return (int) $this->row['status'];
    }

    // expires_at and created_at are not cast: returned as stored, a string or NULL (:156-157, :206-207).
    public function getExpiresAt() {
        return $this->row['expires_at'];
    }

    public function getCreatedAt() {
        return $this->row['created_at'];
    }

    // The decrypted key (:136).
    public function getDecryptedLicenseKey() {
        return $this->row['license_key'];
    }
}

/**
 * DLM's licence repository (DLM Database/Repositories/Licenses.php, a singleton;
 * its get() and update() are DLM Abstracts/AbstractDataRepository.php's). It keeps
 * no cache of its own: AbstractDataRepository.php holds none, so every get()
 * queries afresh and sees the writes before it.
 */
final class DLMY_Licenses {
    /** @var DLMY_Licenses|null */
    private static $instance = null;
    /** @var array[] Licence rows, as the database hands them back. */
    public $rows = array();
    /** @var string 'ok', 'write-fails', 'listener-throws', 'reads-back-other', 'get-exception' or 'get-error'. */
    public $mode = 'ok';
    /** @var array[] Every update() call, as array( licence id, columns ). */
    public $writes = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset( array $rows, $mode = 'ok' ) {
        self::$instance       = new self();
        self::$instance->rows = $rows;
        self::$instance->mode = $mode;
        return self::$instance;
    }

    /**
     * get() (AbstractDataRepository.php:192-215): the rows matching every where
     * column, as models (:207-212). An \Exception from the query is swallowed and
     * yields no rows (:194-205); anything that is not an \Exception escapes.
     */
    public function get( $where = array(), $sort_by = null, $sort_dir = 'DESC', $offset = -1, $limit = -1 ) {
        if ( 'get-error' === $this->mode ) {
            throw new Error( 'an error the query does not catch' );
        }
        try {
            if ( 'get-exception' === $this->mode ) {
                throw new RuntimeException( 'the query failed' );
            }
            $result = array();
            foreach ( $this->rows as $row ) {
                $match = true;
                foreach ( $where as $column => $value ) {
                    $match = $match && (string) $row[ $column ] === (string) $value;
                }
                if ( $match ) {
                    $result[] = $row;
                }
            }
        } catch ( Exception $e ) {
            $result = null;
        }
        $models = array();
        foreach ( (array) $result as $row ) {
            $models[] = new DLMY_License( $row );
        }
        return $models;
    }

    /**
     * update() (AbstractDataRepository.php:250-285): false for a missing row
     * (:252-256); when no column differs (a loose comparison, :261-265) nothing is
     * written and the row is read again (:267-269); otherwise the write, then the
     * row read again and 'dlm_object_updated' fired (:271-280); a failed write
     * gives false (:283). Every call is recorded in $writes, whatever its outcome.
     */
    public function update( $id, $data ) {
        $this->writes[]       = array( $id, $data );
        DLMY_Store::$events[] = 'licence write';
        $at                   = null;
        foreach ( $this->rows as $k => $row ) {
            if ( (int) $row['id'] === (int) $id ) {
                $at = $k;
            }
        }
        if ( null === $at ) {
            return false;
        }
        $row     = $this->rows[ $at ];
        $changes = 0;
        foreach ( $data as $column => $value ) {
            if ( $row[ $column ] != $value ) { // Loose on purpose, as DLM compares.
                $changes++;
            }
        }
        if ( $changes ) {
            if ( 'write-fails' === $this->mode ) {
                return false;
            }
            foreach ( $data as $column => $value ) {
                $row[ $column ] = null === $value ? null : (string) $value;
            }
            $this->rows[ $at ] = $row;
            if ( 'listener-throws' === $this->mode ) {
                throw new RuntimeException( "a 'dlm_object_updated' listener failed after the write" );
            }
        }
        if ( 'reads-back-other' === $this->mode ) {
            $row['status'] = '4'; // The row read back is not what was written.
        }
        return new DLMY_License( $row );
    }
}
class_alias( 'DLMY_Licenses', 'IdeoLogix\DigitalLicenseManager\Database\Repositories\Licenses' );

/*
 * WordPress's hook API, as far as the plugin file uses it. Registrations are kept the
 * way WP_Hook keeps them, so a test can read back exactly what the file registered.
 */
$GLOBALS['dlmy_hooks']   = array(); // Hook name => priority => callback id => array( 'function', 'accepted_args' ).
$GLOBALS['dlmy_actions'] = array(); // Action name => times fired.

/**
 * WP add_filter() (plugin.php:122-131) -> WP_Hook::add_filter() (class-wp-hook.php:91-112).
 * Callbacks are kept per priority (a null priority counts as 0, :92-94) under the id
 * _wp_filter_build_unique_id() gives them, which for a function name is the name itself
 * (plugin.php:1011-1013); adding the same function at the same priority again therefore
 * replaces the entry. accepted_args is stored as an int (class-wp-hook.php:103-106).
 * Only function-name callbacks are modelled.
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
    if ( null === $priority ) {
        $priority = 0;
    }
    $idx = is_string( $callback ) ? $callback : uniqid( 'callback', true );
    $GLOBALS['dlmy_hooks'][ $hook_name ][ $priority ][ $idx ] = array(
        'function'      => $callback,
        'accepted_args' => (int) $accepted_args,
    );
    return true;
}

/** WP add_action() is add_filter() (plugin.php:450-452). */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
    return add_filter( $hook_name, $callback, $priority, $accepted_args );
}

/** WP did_action() (plugin.php:692-700): how many times the action has fired; 0 when never. */
function did_action( $hook_name ) {
    return isset( $GLOBALS['dlmy_actions'][ $hook_name ] ) ? $GLOBALS['dlmy_actions'][ $hook_name ] : 0;
}

/**
 * WP do_action() (plugin.php:492-530): counts the action first (:495-499), passes one
 * empty string when called without arguments (:520-521), then runs the callbacks
 * (WP_Hook::do_action(), class-wp-hook.php:384-392).
 */
function do_action( $hook_name, ...$arg ) {
    $GLOBALS['dlmy_actions'][ $hook_name ] = did_action( $hook_name ) + 1;
    if ( empty( $arg ) ) {
        $arg[] = '';
    }
    dlmy_run_hook( $hook_name, $arg, true );
}

/**
 * WP apply_filters() (plugin.php:175-205): with no callbacks the value comes back as it
 * was (:192-198); otherwise the value goes first in the argument list (:205) and the
 * callbacks run.
 */
function apply_filters( $hook_name, $value, ...$args ) {
    array_unshift( $args, $value );
    return dlmy_run_hook( $hook_name, $args, false );
}

/**
 * WP_Hook::apply_filters() (class-wp-hook.php:332-372): priorities run in ascending order
 * (add_filter() keeps them sorted, :108-111); in a filter each callback's return value
 * becomes the next callback's first argument (:354-356); each callback receives only as
 * many arguments as it accepted (:359-365).
 */
function dlmy_run_hook( $hook_name, array $args, $doing_action ) {
    $value = $args[0];
    if ( empty( $GLOBALS['dlmy_hooks'][ $hook_name ] ) ) {
        return $value;
    }
    $callbacks = $GLOBALS['dlmy_hooks'][ $hook_name ];
    ksort( $callbacks, SORT_NUMERIC );
    $num_args = count( $args );
    foreach ( $callbacks as $list ) {
        foreach ( $list as $the ) {
            if ( ! $doing_action ) {
                $args[0] = $value;
            }
            if ( 0 === $the['accepted_args'] ) {
                $value = call_user_func( $the['function'] );
            } elseif ( $the['accepted_args'] >= $num_args ) {
                $value = call_user_func_array( $the['function'], $args );
            } else {
                $value = call_user_func_array( $the['function'], array_slice( $args, 0, $the['accepted_args'] ) );
            }
        }
    }
    return $value;
}

/**
 * What is registered on a hook, in the order WordPress would run it.
 *
 * @return array List of array( 'callback', 'priority', 'accepted_args' ).
 */
function dlmy_hooked( $hook_name ) {
    $out = array();
    if ( isset( $GLOBALS['dlmy_hooks'][ $hook_name ] ) ) {
        $by_priority = $GLOBALS['dlmy_hooks'][ $hook_name ];
        ksort( $by_priority, SORT_NUMERIC );
        foreach ( $by_priority as $priority => $list ) {
            foreach ( $list as $the ) {
                $out[] = array( 'callback' => $the['function'], 'priority' => $priority, 'accepted_args' => $the['accepted_args'] );
            }
        }
    }
    return $out;
}

/** Forgets every registration; actions already fired stay counted. */
function dlmy_forget_hooks() {
    $GLOBALS['dlmy_hooks'] = array();
}

$GLOBALS['dlmy_options'] = array( 'date_format' => 'j F Y' );

/**
 * WP get_option(): the stored value, or $default_value when there is none.
 */
function get_option( $option, $default_value = false ) {
    return array_key_exists( $option, $GLOBALS['dlmy_options'] ) ? $GLOBALS['dlmy_options'][ $option ] : $default_value;
}

/**
 * WP wp_date() (functions.php:243-256): false for a timestamp that is not a number
 * (:248-249); otherwise the time in the site's timezone (:252-254), which is UTC
 * here. English month names, so there is no locale step.
 */
function wp_date( $format, $timestamp = null, $timezone = null ) {
    if ( null === $timestamp ) {
        $timestamp = time();
    } elseif ( ! is_numeric( $timestamp ) ) {
        return false;
    }
    return gmdate( $format, (int) $timestamp );
}

/**
 * DLM's per-order loop (DLM Integrations/WooCommerce/Orders.php, generateOrderLicenses()
 * :108-178), reduced to the parts that reach the filter:
 * - the order is loaded afresh on every run (:124), and a run stops at once when DLM
 *   has already marked the order complete (:113; isComplete() :696-709);
 * - each line from get_items( 'line_item' ) goes through apply_filters() on
 *   'dlm_woocommerce_order_licenses_creation_for_product' together with the SAME $order
 *   object (:146-147, :167), so the plugin's callback is reached only through what the
 *   plugin registered;
 * - a falsy answer skips the line (:168-171); a truthy one issues a key (:176), after
 *   which createOrderLicenses() marks the order complete and saves it (:363-364).
 * Every product counts as licensed (:154). A line starts from true, as when no other
 * code asked DLM to skip it (:161-167), unless $start gives it another value.
 *
 * @param int   $order_id Order id.
 * @param array $start    Item id => the value the filter receives for that line.
 * @return array 'answers' => item id => the filter's answer; 'minted' => item ids DLM issued a key for.
 */
function dlmy_dlm_generate_order_licenses( $order_id, array $start = array() ) {
    $out   = array( 'answers' => array(), 'minted' => array() );
    $order = wc_get_order( $order_id );
    if ( (int) $order->get_meta( 'dlm_order_complete', true ) ) {
        return $out;
    }
    foreach ( $order->get_items( 'line_item' ) as $item_id => $order_item ) {
        $product                    = $order_item->get_product();
        $create                     = array_key_exists( $item_id, $start ) ? $start[ $item_id ] : true;
        $create                     = apply_filters( 'dlm_woocommerce_order_licenses_creation_for_product', $create, $order, $product, $order_item );
        $out['answers'][ $item_id ] = $create;
        if ( ! $create ) {
            continue;
        }
        $out['minted'][] = $item_id;
        $order->update_meta_data( 'dlm_order_complete', 1 );
        $order->save();
    }
    return $out;
}
