<?php
namespace OnlineShop;
use ActiveRecords\ActiveRecords;

class Shop
{
  private $goods;
  private $cart;
  private $order;
  private $promo;
  
  public function __construct(ActiveRecords $db)
  {
    $this->goods = new Goods($db);
    $this->cart = new Cart($this->goods);
  }
  
  public function cart()
  {
    return $this->cart;
  }
  
  public function goods()
  {
    return $this->goods;
  }
}
?>
