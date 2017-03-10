<?php
namespace OnlineShop;
use ActiveRecords\ActiveRecords;
/**
 * This class supports promo actions. There are several types:
 * - discount in absolute amount or percentage, applied to specific items or categories
 * - gifts added upon choosing specific items or items of a specific category
 * - items suggested upon choosing specific items or items of a specific category
 *
 * Promo can be applied either after entering promotion code or without it.
 *
 * Promo rules are stored somewhere (JSON, DB) and has the following structure:
 * $rules = array(
 *   array(
 *     'appliesTo' => 'items',           # can be items, category
 *     'objects'   => array('11','13'),  # array of item ID or category names
 *     'type'      => 'absolute',        # one of: absolute, percentage, gift, suggest
 *     'value'     => '500',             # 500 (Rub), or 50(%), or 11 (ID of gift/suggest)
 *     'code'      => 'PROMO500'         # if set, promo applied after entering the code
 *    ),
 *    array( ... etc
 * );
 *
 */
class Promo
{
  /**
   * @var   object
   *
   * ActiveRecords instance
   */
  private $db = array();

  /**
   * @var   string
   *
   * Set Promo Code
   */
  private $code;
  
  /**
   * @var   array
   * 
   * Keep found promo rules
   */
  private $rules = array();

  /**
   * @param  object  ActiveRecords instance
   */
  public function __construct(ActiveRecords $db)
  {
    $this->db = $db;
    $this->db->setTable('promo');
  }

  /**
   * Set the promotion code
   *
   * @param  string
   * @return bool   true if code is found in the rules
   */
  public function setPromoCode($code)
  {
    if ( ! is_string( $code ) || empty( $code ) ) return;
    
    if ( $this->db->getOne(array('code'=>$code)) )
    {
      $this->code = $code;
      return true;
    }

    return false;
  }

  /**
   * Remove the promotion code
   *
   */
  public function unsetPromoCode()
  {
    $this->code = null;
  }

  /**
   * Looks for rules for a given item & category and sets 
   * found rules to the class variable.
   *
   * @param  string  item id
   * @param  string  category name
   */
  public function lookup($item_id, $tags = array())
  {
    $result   = array();
    $criteria = array();

    if ( $this->code )
      $criteria['code'] = $this->code;
      
    // lookup items first
    $criteria['appliesTo'] = 'items';
    $criteria['objects']   = $item_id;
    
    $this->rules = $this->db->get($criteria);

    // lookup in categories
    $criteria['appliesTo'] = 'categories';
    foreach ($tags as $tag)
    {
      $criteria['objects'] = $tag;
      if ( $found = $this->db->getOne($criteria) )
        $this->rules = array_merge($this->rules, $found);
    }    
  }

  /**
   * Returns discount value based on found promo rules.
   * If multiple rules apply, only the first is used other are skipped.
   *
   * @param   string  item id
   * @param   string  category name
   * @return  array
   */
  public function getDiscount()
  {
    $discount = array();

    foreach($this->rules as $rule)
      if ( $rule['type'] === 'absolute' || $rule['type'] === 'percentage' )  // filter out suggests
      {
        $discount['type']  = $rule['type'];
        $discount['value'] = $rule['value'];
        break;
      }

    return $discount;
  }

  /**
   * Returns suggest items from found $rules if any.
   *
   * @param   string  item id
   * @param   string  category name
   * @return  array   plain array with list of suggest items
   */
  public function getSuggest()
  {
    $suggest = array();

    if ( $this->rules )
      foreach( $this->rules as $rule )
        if ( $rule['type'] === 'suggest' )
          $suggest[]  = $rule['value'];

    return $suggest;
  }

  /**
   * Returns gift items from found promo rules if any.
   *
   * @param   string  item id
   * @param   string  category name
   * @return  array   plain array with list of suggest items
   */
  public function getGifts()
  {
    $gifts = array();

      foreach($this->rules as $rule)
        if ( $rule['type'] === 'gift' )
        {
          if ( ! isset($rule['policy']) )
            trigger_error('For promo rule of gift type the policy param must be set.', E_USER_ERROR);

          $gifts[]  = array(
            'item_id' => $rule['value'],
            'policy'  => $rule['policy']
          );
        }

    return $gifts;
  }

  public function addRule($rule)
  {
    $this->db->add($rule);
  }
  
  public function deleteRule($rule_id)
  {
    $this->db->delete(array('id'=>$rule_id));
  }
  
  public function updateRule($rule)
  {
    $rule_id = $rule['id'];
    unset($rule['id']);
    $this->db->update(array('id'=>$rule_id), $rule);
  }
}
?>
