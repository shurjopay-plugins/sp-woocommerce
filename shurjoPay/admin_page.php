<?php

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class TT_Example_List_Table extends WP_List_Table {

	function __construct(){
        global $status, $page;
                
        //Set parent defaults
        parent::__construct( array(
            'ajax'      => false        //does this table support ajax?
        ) );
        
    }

    function column_default($item, $column_name){
        switch($column_name){
        	case 'id':
            case 'transaction_id':
            case 'order_id':
            case 'invoice_id':
            case 'currency':
            case 'amount':
            case 'instrument':
            case 'bank_status' :
            case 'bank_trx_id':
            case 'transaction_time':
                return $item[$column_name];
            default:
                return print_r($item,true); //Show the whole array for troubleshooting purposes
        }
    }

    function column_title($item){
        
        //Build row actions
        $actions = array(
            'edit'      => sprintf('<a href="?page=%s&action=%s&id=%s">Edit</a>',$_REQUEST['page'],'edit',$item['id']),
            'delete'    => sprintf('<a href="?page=%s&action=%s&id=%s">Delete</a>',$_REQUEST['page'],'delete',$item['id']),
        );
        
        //Return the title contents
        return sprintf('%1$s <span style="color:silver">(id:%2$s)</span>%3$s',
            /*$1%s*/ $item['id'],
            /*$2%s*/ $item['transaction_id'],
            /*$3%s*/ $this->row_actions($actions)
        );
    }


    function column_cb($item){
        return sprintf(
            '<input type="checkbox" transaction_id="%1$s[]" value="%2$s" />',
            'id',  
            /*$2%s*/ $item['id']                
        );
    }

    function get_columns(){
        $columns = array(
            'cb'    => '<input type="checkbox" />', 
            'transaction_id'   => 'Transaction ID',
            'order_id'  => 'Order ID',
            'invoice_id' => 'Invoice ID',
            'currency' => 'Currency',
            'amount' => 'Amount',
            'instrument' => 'Instrument',
            'bank_status' => 'Bank status',
            'bank_trx_id' => 'Bank Trx ID',
            'transaction_time' => 'Request Time'
        );
        return $columns;
    }

    function get_sortable_columns() {
        $sortable_columns = array(
            'instrument'     => array('method',false),     //true means it's already sorted
            'bank_status'    => array('payment_status',false),
            'transaction_time'  => array('transaction_time',false)
        );
        return $sortable_columns;
    }


    function get_bulk_actions() {
        $actions = array(
            'delete'    => 'Delete'
        );
        return $actions;
    }

    function process_bulk_action() {

    	global $wpdb; 
        $table_name = $wpdb->prefix . 'sp_orders';
        // var_dump($_REQUEST);
    	$id = isset($_REQUEST['id'][0])?$_REQUEST['id'][0]:'';
        if( 'delete'===$this->current_action() ) {
            $wpdb->delete($table_name, array('id'=>$id),array('%d'));
        }
        
    }

    function prepare_items() {

        global $wpdb; 
        $table_name = $wpdb->prefix . 'sp_orders';
        $per_page = 5;
        $current_page = $this->get_pagenum();
		if ( 1 < $current_page ) {
			$offset = $per_page * ( $current_page - 1 );
		} else {
			$offset = 0;
		}
		$search = '';
		
		$customvar = ( isset($_REQUEST['customvar']) ? $_REQUEST['customvar'] : '');
		if($customvar != '') {
			$search_custom_vars= "AND action LIKE '%" . esc_sql( $wpdb->esc_like( $customvar ) ) . "%'";
		} else	{
			$search_custom_vars = '';
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$search = "AND transaction_id LIKE '%" . esc_sql( $wpdb->esc_like( $_REQUEST['s'] ) ) . "%'";
		}

        $columns = $this->get_columns();        
        $hidden = array();
        $sortable = $this->get_sortable_columns();   
        $this->_column_headers = array($columns, $hidden, $sortable); 
        $this->process_bulk_action();
        $data = $wpdb->get_results( " SELECT * FROM {$table_name} ". $wpdb->prepare( "ORDER BY id DESC LIMIT %d OFFSET %d;", $per_page, $offset ),ARRAY_A);
        
       	usort( $data, array( &$this, 'usort_reorder' ) );
        $count = $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name} WHERE 1 = 1 {$search} {$search_custom_vars}" );

        $this->items = $data;

        		// Set the pagination
		$this->set_pagination_args( array(
			'total_items' => $count,
			'per_page'    => $per_page,
			'total_pages' => ceil( $count / $per_page )
		));

    }
}

add_action( 'admin_menu', function() {
    add_menu_page(
        'shurjoPay Payments',
        'shurjoPay Payments',
        'manage_options',
        'shurjopay_payments',
        'show_transaction_list'
    );

});

function show_transaction_list()
{
	$testListTable = new TT_Example_List_Table();
    $testListTable->prepare_items();
    
?>
    <div class="wrap">
        
        <div id="icon-users" class="icon32"><br/></div>
        <h2>Payment List</h2>
        <!-- Forms are NOT created automatically, so you need to wrap the table in one to use features like bulk actions -->
        <form id="movies-filter" method="get">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
            <?php $testListTable->display() ?>
        </form>
        
    </div>
    <?php
   }