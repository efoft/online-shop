<?php
namespace OnlineShop;

use ActiveRecords\ActiveRecords;
class Order
{
  /**
   * @var   object
   *
   * ActiveRecords instance
   */
  private $db;
  
  /**
   * @var   array
   *
   * DB fields of an order
   */
  private $fields = array(
      'number',
      'amount',
      'payment',
      'firstname',
      'lastname',
      'phone',
      'email',
      'address',
      'comment',
      'status',
      'items',
      'discount'
    );
  
  public function __construct(ActiveRecords $db) {
    $this->db  = $db;
    $this->db->setTable('orders');
    //$this->db->setUniqueRecordFields(array('name','weight','price'));
    //$this->db->setMandatoryFields(array('name','price'));
  }

  /**
   * Inserts new orders data to DB and return order number.
   *
   * @param   string  total money of the order
   * @param   array   items in the order
   * @param   string  discount
   * @return  string
   */
  public function create($amount, $items, $discount = 0)
  {  
    // Some of the order data we know...
    $source = array(
      'amount'   => $amount,
      'status'   => 'pending',
      'items'    => serialize($items),
      'discount' => $discount
    );
    // ... some have to take from POST
    $source = array_merge($source, $_POST);

    if ( $order_id = $this->db->add($this->filterInput($source)) )
      return self::generateOrderNumber($order_id);
    elseif ( $error = $this->db->getError() )
      $this->debug && trigger_error(sprintf('Could not create a new order, error %s, details: %s, %s.', key($error), $error['errmsg'], $error['extinfo']));
    else
      $this->debug && trigger_error('Could not create a new order, unknown error.', E_USER_ERROR);
  }

  /**
   * This function is called after order confirmation to update order's status.
   *
   * @param   string   the order number
   * @param   string   new status to set
   */
  public function updateStatus($o_num, $status)
  {
    $this->db->update(array('number'=>$o_num), array('status'=>$status));
  }

  /**
   * Extracts from DB all the orders on the date specified.
   *
   * @param   string  the date in format DD.MM.YYYY
   * @return  array
   */
  public function fetchAll($date = NULL)
  {
    $criteria = array();
    if ( $date )
    {
      $d = explode('.', $date);
      $criteria = '/' . $d[2] . '-' . $d[1] . '-' . $d[0] . '.*/';
    }

    return $this->db->get($criteria);
  }

  /**
   * Extracts from DB specific order.
   *
   * @param   string  the order number
   * @return  array
   */
  public function fetchOne($o_num)
  {
    return $this->db->getOne(array('number'=>$o_num));
  }

  private static function generateOrderNumber($o_id) {
    $prefix = date('ymd') . '-';
    return $prefix . $o_id;
  }
  
  private function filterInput($data)
  {
    return array_intersect_key($data, array_flip($this->fields));
  }
}
?>
