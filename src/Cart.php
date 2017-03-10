<?php
namespace OnlineShop;
use OnlineShop\Promo;
use OnlineShop\Goods;
/**
 * Implement shopping cart for online trading.
 *
 * It uses $_POST to retrieve items details. Below is a structure of
 * the item object:
 * item = array(
 *         'name',
 *         'qtty'.
 *         'price'
 *        );
 */
class Cart {
  /**
   * @var   object
   *
   * Instance of class Promo.
   */
  private $promo;

  /**
   * @var   boolean
   *
   * Flag saying that there has been no calc() so far.
   */
  private $dirty = false;

  /**
   * @var   array
   *
   * Array containing goods info.
   */
  private $goods = array();

  /**
   * @var
   *
   * Some class variables. Description is on the right.
   */
  private $total_base_amount;     // total amount based on list prices (before discount)
  private $total_final_amount;    // total amount after discount
  private $total_discount;        // total discount amount
  private $grand_total;           // total money after discount + aux payments
  private $aux_amount = array();  // keeps all additional payments like delivery

  /**
   * Class constructor.
   *
   */
  public function __construct(Goods $goods) {
    if ( session_id() == '' ) session_start();
    self::initCart();
    $this->goods = $goods;
  }

  /**
   * Sets a pointer to class Promo instance. If set, calculation are consulted
   * with promo actions.
   *
   * @param  instance
   */
  public function setPromo( Promo $promo )
  {
    $this->promo = $promo;
  }

  /**
   * Cart initialization: defining session variable.
   *
   */
  private function initCart()
  {
    if ( ! isset($_SESSION['cart_items']) )
      $_SESSION['cart_items'] = array();

    if ( ! isset($_SESSION['cart_suggest']) )
      $_SESSION['cart_suggest'] = array();

    $this->total_amount   = 0;
    $this->total_discount = 0;
    $this->grand_total    = 0;
    $this->dirty          = true;
  }

  /**
   * Destroy session variable clearing the cart content
   * than re-init the cart.
   *
   */
  public function emptyCart()
  {
    unset($_SESSION['cart_items']);
    unset($_SESSION['cart_suggest']);
    self::initCart();
  }

  /**
   * Checks if any items are in the cart.
   *
   * @return  bool  true if the cart is empty
   */
  public function isCartEmpty()
  {
    return empty($_SESSION['cart_items']);
  }

  /**
   * This function returns true if item is already in the cart.
   *
   * @return  boolean  true if the item is in the cart
   */
  public function isItemInCart($item_id)
  {
    return isset($_SESSION['cart_items'][$item_id]);
  }
  
  /**
   * Applies promocodes and calculates total figures.
   *
   */
  public function calc()
  {
    if ( sizeof($_SESSION['cart_items']) === 0 ) return;

    $this->total_base_amount  = 0;
    $this->total_final_amount = 0;
    
    $this->manageGifts();

    foreach( $_SESSION['cart_items'] as $item_id=>&$item )
    {
      
      $this->applyPromo($item);

      $this->total_base_amount   += $item['amount'];
      $this->total_final_amount  += $item['final_amount'];
    }
    $this->total_discount = $this->total_base_amount - $this->total_final_amount;

    // aux
    $total_aux = 0;
    foreach($this->aux_amount as $k=>$v)
      $total_aux += $v;

    $this->grand_total = $this->total_final_amount + $total_aux;
    $this->dirty = false;
  }

  /**
   * This function updates the cart item. If the item does not exist
   * it will be added. Final item qtty in the cart is set how it's
   * specified in item['qtty'], it's not incremented or decremented.
   *
   * Form the argument as follows:
   * array(
   *   'id'   => $_POST['item_id'],
   *   'qtty' => $_POST['item_qtty']
   * );
   *
   * @param  string  item id
   * @param  integer quantity
   */
  public function updateItem($item_id, $qtty)
  {
    if ( ! $item = $this->goods->get($item_id) )
      trigger_error(sprintf('Item with specified id "%s" is not found in the catalog.', $item_id), E_USER_ERROR);

    $purchase = ( $cart_item = $this->getCartItem($item_id) ) ?  $cart_item : array();
    $qtty = intval($qtty);

    if ( $qtty > 0 )
    {
      $purchase['id']     = $item_id;
      $purchase['name']   = $item['name'];
      $purchase['defimg'] = $item['defimg'];
      $purchase['price']  = $item['price'];
      $purchase['qtty']   = $qtty;
      $purchase['amount'] = $item['price'] * $qtty;

      $_SESSION['cart_items'][$item_id] = $purchase;
      $this->dirty = true;
    }
    else
      $this->deleteItem( $item_id );
  }

  /**
   * Applies promo information (discount & suggest) to the purchase item.
   *
   * @param  array  (by ref) purchase
   */
  private function applyPromo(&$purchase)
  {
    // so far we don't know about discount...
    $purchase['discount']       = 0;
    $purchase['discount_type']  = 'absolute';
    $purchase['discount_price'] = $purchase['price'];
    $purchase['final_amount']   = $purchase['amount'];

    if ( $this->promo )
    {
      $item_id = $purchase['id'];
      $this->promo->lookup($item_id, $this->goods->getOne($item_id, 'tags'));

      if ( $discount = $this->promo->getDiscount() )
      {
        switch ( $discount['type'] )
        {
          case 'absolute':
            $purchase['discount_price'] = $purchase['price'] - $discount['value'];
            break;
          case 'percentage':
            $purchase['discount_price'] = (( 100 - $discount['value'] )/100) * $purchase['price'];
            break;
        }

        $purchase['discount']      = $discount['value'];
        $purchase['discount_type'] = $discount['type'];
        $purchase['final_amount']  = $purchase['discount_price'] * $purchase['qtty'];
        if ( $purchase['final_amount'] < 0 ) $purchase['final_amount'] = 0;
      }

      if ( $suggest = $this->promo->getSuggest() )
        foreach ($suggest as $suggest_item)
          if ( ! $this->isItemInCart($suggest_item) && ! in_array($suggest_item, $_SESSION['cart_suggest']) )  // suggest just once
            $_SESSION['cart_suggest'][] = $suggest_item;
    }
    $this->dirty = true;
  }

  /**
   * Returns array of cart items.
   *
   * @return  array
   */
  public function getCartItems()
  {
    if ($this->dirty) $this->calc();
    return $_SESSION['cart_items'];
  }

  /**
   * Lookup cart item by its id and returns either the whole array or specific
   * requested field.
   *
   * @param  string  item id
   * @param  string  (optional)  array key name
   */
  public function getCartItem($item_id, $field = NULL)
  {
    if ( ! isset($_SESSION['cart_items'][$item_id]) )
      return;

    $result = $_SESSION['cart_items'][$item_id];

    if ( ! is_null($field) )
      if ( ! isset($result[$field]) )
        return;
      else
        return $result[$field];

    return $result;
  }

  /**
   * Adds new quantity of item to the cart. First it checks if the item is already
   * in the cart and increment the qtty than updates the item.
   *
   * @param  string  item id
   * @param  string  qtty
   */
  public function addItem($item_id, $qtty)
  {
    if ( $already = $this->getCartItem($item_id, 'qtty') )
      $qtty += $already;

    $this->updateItem($item_id, $qtty);
  }

  /**
   * Removes purchase from the cart.
   *
   * @param  string  item id
   */
  public function deleteItem($item_id)
  {
    if ( isset($_SESSION['cart_items'][$item_id]) )
    {
      unset($_SESSION['cart_items'][$item_id]);
      $this->dirty = true;
    }
  }

  /**
   * Sets or updates one of the values in cart item.
   *
   * @param  string  item id
   * @param  string  key name
   * @param  string/null  new value, if null - removed
   */
  private function setItemProp($item_id, $prop_name, $prop_value = NULL)
  {
    if ( ! $this->isItemInCart($item_id) )
      return false;

    if ( is_null($prop_value) )
    {
      unset( $_SESSION['cart_items'][$item_id][$prop_name] );
      return true;
    }

    $_SESSION['cart_items'][$item_id][$prop_name] = $prop_value;
    return true;
  }

  /**
   * This function updates items quantities.
   *
   * @param  array  ($item_id => new qtty)
   */
  public function updateCart($data)
  {
    foreach($data as $item_id=>$qtty)
      if ( is_numeric($qtty) && $qtty >=0 )
        if ( $qtty == 0 ) // If new value is 0, remove the item
          $this->deleteItem( $item_id );
        else // otherwise recalculate
          if ( $qtty !== $this->getCartItem($item_id, 'qtty') )
            $this->updateItem($item_id, $qtty);
  }

  /**
   * On each subsequent call the function returns suggest items one by one.
   * Returned item is removed from the memory.
   *
   * @return  string  suggested item id
   */
  public function getSuggest()
  {
    if ( isset($_SESSION['cart_suggest']) && count($_SESSION['cart_suggest']) > 0 )
    {
      reset( $_SESSION['cart_suggest'] );
      $k = key( $_SESSION['cart_suggest'] );
      $suggest = $_SESSION['cart_suggest'][$k];
      unset( $_SESSION['cart_suggest'][$k] );
      return $suggest;
    }
  }

  /**
   * Iterates over the cart items ignoring items with tag "gift".
   * For normal items it checks promo for gift and updates their qtty.
   * Promo rules for gifts must specify policy.
   * It can be either 'once' (only one such gift for whole cart) or 'each' (add one gift per each eligible cart item).
   *
   */
  private function manageGifts()
  {
    if ( $this->isCartEmpty() || ! $this->promo ) return;

    // remove all gifts
    foreach($_SESSION['cart_items'] as $item_id=>$purchase)
      if ( isset($purchase['gift']) )
        $this->deleteItem($item_id);

    // Assign gifts from scratch
    foreach($_SESSION['cart_items'] as $item_id=>$purchase)
    {
      $this->promo->lookup($item_id, $this->goods->get($item_id, 'tags'));

      if ( $gifts = $this->promo->getGifts() )
        foreach($gifts as $gift)
        {
          if ( $gift['policy'] === 'once' )
            $this->addItem($gift['item_id'], 1);
          elseif ( $gift['policy'] === 'each' )
            $this->addItem($gift['item_id'], $purchase['qtty']);

          $this->setItemProp($gift['item_id'], 'gift', true);
        }
    }
  }

  /**
   * Returns total cost of the cart either before or after discount.
   * For cost with aux payment call getGrandTotal().
   *
   * @param   bool  
   * @return  string
   */
  public function getTotalAmount($with_discount = false)
  {
    if ($this->dirty) $this->calc();
    return $with_discount ? $this->total_final_amount : $this->total_base_amount;
  }

  /**
   * Returns total cost of the cart plus aux cost
   * and applied discount.
   *
   * @return  string
   */
  public function getGrandTotal()
  {
    if ($this->dirty) $this->calc();
    return $this->grand_total;
  }

  /**
   * Returns the total qtty of all cart items.
   *
   * @return  string
   */
  public function getTotalQtty()
  {
    $total_qtty = 0;

    foreach( $_SESSION['cart_items'] as $purchase )
      $total_qtty += $purchase['qtty'];

    return $total_qtty;
  }

  /**
   * Returns stored total_discount value.
   *
   * @return  string
   */
  public function getTotalDiscount()
  {
    if ($this->dirty) $this->calc();
    return $this->total_discount;
  }

  /**
   * Set additional payment. This payments are not automatically
   * used to get total amount. Just for storing purposes.
   *
   * @access  public
   * @param   string  the payment's name
   * @param   string  value
   */
   public function setAuxAmount($name, $value)
   {
     $this->aux_amount[$name] = $value;
   }

  /**
   * Returns the amount of the stored value.
   *
   * @access  public
   * @param   string  the payment's name
   * @return  string  value
   */
  public function getAuxAmount($name)
  {
    if ( isset($this->aux_amount[$name]) )
      return $this->aux_amount[$name];
       
      return;
  }
}
?>
