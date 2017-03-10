<?php
namespace OnlineShop;
use ActiveRecords\ActiveRecords;

class Goods
{
  /**
   * @var
   *
   * Array of goods. For structure see $fields below.
   */
  private $goods  = array();

  /**
   * @var
   *
   * Array of the fields the item object must consist of.
   */
  private $fields = array(
    'name',
    'weight',
    'size',
    'price',
    'desc',
    'imgs',
    'defimg',
    'tags',
    'active'
  );
  /**
   * @var   object
   *
   * ActiveRecords instance.
   */
  private $db;

  /**
   * @var   boolean
   *
   * Enable via setDebug() for debugging.
   */
  private $debug = false;

  /**
   * Constructior.
   *
   * @param   string   $db      ActiveRecord layer interface
   */
  public function __construct(ActiveRecords $db)
  {
    $this->db = $db;
    $this->db->setTable('goods');
    $this->db->setUniqueRecordFields(array('name','weight','price'));
    $this->db->setMandatoryFields(array('name','price'));
  }
  
  public function setDebug($mode = false)
  {
    $this->debug = (bool)$mode;
  }

  /**
   * Returns full array or selected set of fields of a single goods item.
   *
   * @param   string
   * @param   string|array|null   could be single field name, array of field names or nothing
   * @return  array
   */
  public function get($id, $props = NULL)
  {
    return $this->db->getOne(array('id'=>$id), (array)$props);
  }

  /**
   * Returns array of goods optionally matching the criteria.
   *
   * @param  array  (optional) array of match filters
   * @return array  array of goods
   */
  public function find($criteria = array())
  {
    return $this->db->get($criteria);
  }

  /**
   * Add a new tag to the array of tags related to the item.
   * If the tag is already there nothing is happening.
   *
   * @param   string    $id
   * @param   string    $tag
   */
  public function addTag($id, $tag)
  {
    $this->db->update(array('id'=>$id), array('$addToSet'=>array('tags'=>$tag)));
  }

  /**
   * Remove existing tag from the array of tags related to the item.
   *
   * @param   string    $id
   * @param   string    $tag
   */
  public function delTag($id, $tag)
  {
    $this->db->update(array('id'=>$id), array('$pull'=>array('tags'=>$tag)));
  }
  
  /**
   * Adds a new item into DB.
   *
   * @param  array  $item
   */
  public function add($item)
  {
    if ( $this->db->add($this->filterInput($item)) )
      $msg = array('status'=>'success', 'action'=>'add_item', 'info'=>$item['name']);
    else if ( $error = $this->db->getError() )
      $msg = array('status'=>'failure', 'action'=>'add_item', 'info'=>$error);
    else
      $msg = array('status'=>'failure', 'action'=>'add_item', 'info'=>'unknown error');

    return $msg;
  }

  /**
   * Delete an item from DB.
   *
   * @param  string  ID of the item to delete
   */
  public function del($id)
  {
    $this->db->remove(array('id'=>$id));
  }

  /**
   * Adds a new records of an item's photo into DB.
   *
   * @param   string   $item_id   item id
   * @param   string   $imgfile   image file name
   */
  public function addImg($item_id, $imgfile)
  {
    // if this is the first added img, set it as defimg also
    $defimg = array();
    if ( ! $this->db->getOne(array('id'=>$item_id), array('imgs')))
      $defimg = array('defimg'=>$imgfile);

    if ( $this->db->update(array('id'=>$item_id), array_merge($defimg, array('$addToSet'=>array('imgs'=>$imgfile)))) )
      $msg = array('status'=>'success', 'action'=>'add_img', 'info'=>$imgfile);
    else if ( $error = $this->db->getError() )
      $msg = array('status'=>'failure', 'action'=>'add_img', 'info'=>$error);
    else
      $msg = array('status'=>'failure', 'action'=>'add_img', 'info'=>'unknown error');

    return $msg;
  }
  
  /**
   * Remove the records of the item's photo from DB.
   *
   * @param  string
   * @param  string  image file name
   */
  public function delImg($item_id, $imgfile)
  {
    $this->db->update(array('id'=>$item_id), array('$pull'=>array('imgs'=>$imgfile)));
    
    // if this img is defimg, remove it, and replace with next img if still exists
    if ( $this->db->getOne(array('id'=>$item_id, 'defimg'=>$imgfile)) )
    {
      $defimg = ( $imgs = $this->db->getOne(array('id'=>$item_id, array('imgs'))) ) ? $imgs[0] : null;
      $this->db->update(array('id'=>$item_id), array('defimg'=>null));
    }
  }

  /**
   * Sets default image for the item. Only one image can be default.
   *
   * @param  string  item id
   * @param  string  full path to the image
   */
  public function setDefaultImg($id, $imgfile)
  {
    $this->db->update(array('id'=>$id), array('defimg'=>$imgfile) );
  }

  /**
   * Update an item.
   *
   * @param  array
   */
  public function update($item)
  {
    if ( ! isset($item['id']) )
      throw new \LogicException('No id field found for update operation');
    
    if ( $this->db->update(array('id'=>$item['id']), $this->filterInput($item)) )
      $msg = array('status'=>'success', 'action'=>'update_item', 'info'=>$item['name']);
    elseif ( $error = $this->db->getError() )
      $msg = array('status'=>'failure', 'action'=>'update_item', 'info'=>$error);
    else
      $msg = array('status'=>'failure', 'action'=>'update_item', 'info'=>'unknown error');

    return $msg;
  }

  /**
   * Sets item state to active=true or false.
   *
   * @param  string/number  item id
   * @param  bool           (optional) active or not, if omitted then state is toggled
   */
  public function toggleActive($id, $active = null)
  {
    if ( is_null($active) )
      if ( $item = $this->getItem($id) )
      {
        $cur_state = isset($item['active']) ? (bool) $item['active'] : false;
        $active = ! $cur_state;
      }
      else
        $this->debug && trigger_error(sprintf('Item with id "%s" is not found.', $id));

    $this->db->update(array('id'=>$id), array('active'=> (bool) $active) );
  }

  /**
   * Intersects only useful data from input.
   *
   * @param   array     $data   usually this is raw $_POST from form submit
   * @return  array             filtered set of fields
   */
  private function filterInput($data)
  {
    $filtered = array_intersect_key($data, array_flip($this->fields));
    if ( ! isset($filtered['active']) ) $filtered['active'] = true;
    return $filtered;
  }
}
?>
