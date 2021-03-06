<?php

/**
 *
 * @author Ivan Teleshun <teleshun.ivan@gmail.com>
 * @link http://molotoksoftware.com/
 * @copyright 2016 MolotokSoftware
 * @license GNU General Public License, version 3
 */

/**
 * 
 * This file is part of MolotokSoftware.
 *
 * MolotokSoftware is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * MolotokSoftware is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with MolotokSoftware.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Ставка на лот
 */
class NewBidAction extends CAction
{
    public function makeBid($lotId, $user_id, $price, $skip_max_valid = false, $send_active_lots_nf = true) {
        $bid = new Bid($price, $user_id, $lotId);
        $bid->skip_max_valid = $skip_max_valid;
        $bid->send_active_lots_nf = $send_active_lots_nf;

        $bid->onAfterBid = function($event) {

            Yii::app()->db->createCommand()
                ->update('auction', array(
                    'current_bid' => $event->params['bid_id']
                ), 'auction_id=:auction_id', array(':auction_id' => $event->params['lot_id']));

        };

        $bid->onAfterBid = function ($event) {
            //после успешной ставки
            Yii::log('on after bid');

            //увеличить счетчик события Активные лоты
            $lot = Yii::app()->db->createCommand()
                ->select('a.owner')
                ->from('auction a')
                ->where('auction_id=:id', array(':id' => $event->params['lot_id']))
                ->queryRow();


            /**
             * @notify
             *
             * Активные лоты. По вашему лоту появилась новая ставка
             */
            if ($event->params['send_active_lots_nf']) {
                $params = [
                    'linkItem'     => BaseAuction::staticGetLink($event->params['lot']['name'], $event->params['lot']['auction_id']),
                    'bidPrice'     => $event->params['bidPrice'],
                    'lotName'      => $event->params['lot']['name'],
                    'userLink'     => User::model()->findByPk($event->params['owner'])->getLink(),
                ];
                $ntf = new Notification($lot['owner'], $params, Notification::TYPE_ACTIVE_LOTS);
                $ntf->send();
            }


            $ce = new ActiveLots();
            $ce->inc($lot['owner'], $event->params['lot_id']);
            //проверить были ли ставки на лот
            $owners = array(Yii::app()->user->id);
            $lead_auto = AutoBid::getAuctionLeader($event->params['lot_id']);
            if($lead_auto) $owners[] = $lead_auto->user_id;

            $sql = 'SELECT * FROM (SELECT b . * FROM bids b WHERE b.lot_id =:lot_id and st_notify=0 AND owner NOT IN ('.implode(',', $owners).') ORDER BY b.created DESC) AS inv GROUP BY owner';
            $bids = Yii::app()->db->createCommand($sql)->queryAll(true, array(':lot_id' => $event->params['lot_id']));


            foreach ($bids as $bid) {
                //увеличить счетчик события Активные ставки
                $ce = new ActiveBetsItem();
                $ce->inc($bid['owner'], $event->params['lot_id']);


                //ваша ставка перебита
                $params = [
                    'linkItem'     => BaseAuction::staticGetLink($event->params['lot']['name'], $event->params['lot']['auction_id']),
                    'bet'          => $bid['price'],
                    'lotName'      => $event->params['lot']['name'],
                ];
                $ntf = new Notification($bid['owner'], $params, Notification::TYPE_RATE_SLAUGHTERED);
                $ntf->send();

                //меняем статус на уведомлен о перебитие ставки
                Yii::app()->db->createCommand()
                    ->update('bids', array(
                        'st_notify' => 1
                    ), 'owner=:owner and lot_id=:lot_id', array(
                        ':owner' => $bid['owner'],
                        ':lot_id' => $bid['lot_id']
                    ));
            }
        };

        if ($n = $bid->createBid()) {
            return $bid;
        } else {
            RAjax::modelErrors($bid->getErrors());
        }
        return $bid;
    }

    /**
     * @param $lotId
     * @param $price
     *
     * @return Auction
     */
    public function getLot($lotId, $price) {
        $lot = Auction::model()->findByPk($lotId);
        if ($lot !== false) {
            if ($lot->price > 0 && $price > $lot->price) {
                RAjax::error(array(
                    'type' => 'COMMON_ERROR',
                    'message' => 'Ставка не может быть больше блиц цены'
                ));
            }

            return $lot;
        } else {
            RAjax::error();
        }
    }

    public function run()
    {
        if (Yii::app()->user->isGuest) { RAjax::error(array('type' => 'NOT_AUTHORIZED', 'returnUrl' => Yii::app()->user->loginUrl)); }

        $lotId = Yii::app()->request->getParam('lotId', null);
        $priceFloat = Yii::app()->request->getParam('price', null);
        $price = round($priceFloat, 2);


        $lot = $this->getLot($lotId, $price);
        $current_bid = BidAR::model()->findByPk($lot->current_bid);
        $lead_auto_bid = AutoBid::getAuctionLeader($lotId);

        // Проверяем если лот не завершился.
        $lotBiddingTime = strtotime($lot->bidding_date);
        $mysqlTime = strtotime(Yii::app()->getDb()->createCommand("SELECT CURRENT_TIMESTAMP")->queryScalar());

        if ($lotBiddingTime <= $mysqlTime) {
            RAjax::error([
                'type'       => 'LOT_COMPLETED',
                'message'    => 'Торги по лоту были закончены',
            ]);
        }

        if($lead_auto_bid && $lead_auto_bid->price == floatval($price)) {
            RAjax::error(array(
                'type' => 'COMMON_ERROR',
                'message' => 'Такая ставка сделана другим пользователем ранее. Поставьте другую ставку'
            ));
        }

        $calc_price = AutoBid::calcAuctionPrice($lotId); // Текущая цена, которую можно поставить

        if($price < $calc_price) {
            RAjax::error(array(
                'type' => 'COMMON_ERROR',
                'message' => 'Ваша ставка ('.floatval($price).' руб.) должна быть больше текущей ставки + минимальный шаг ('.($calc_price).')'
            ));
        }

        AutoBid::setMaxPrice($lotId, Yii::app()->user->id, $price);

        if(!$current_bid || $current_bid->owner != Yii::app()->user->id) {
            // Делаем ставку от пользователя по текущей цене
            $bid_price = $lead_auto_bid && ($lead_auto_bid->price > $price) ? $price : $calc_price; // Если ставка лидера больше моей цены, то ставим мою цену, иначе рассчитанную стоимость
            if($lead_auto_bid && ($lead_auto_bid->price < $price)) $bid_price = $lead_auto_bid->price > $calc_price ? $lead_auto_bid->price + 1 : $calc_price;
            if($bid_price > $price) $bid_price = $price; // Если мы пытаемся поставить больше, чем ввел пользователь, то ставим столько, сколько ввел пользователь

            $rebid = $lead_auto_bid && $bid_price < $lead_auto_bid->price;

            $n = $this->makeBid($lotId, Yii::app()->user->id, $bid_price, false, !$rebid);

            // Если текущая цена меньше рассчитанной через AutoBid, то делаем ставку от пользователя, чей бид больше
            if ($rebid) {
                $n2 = $this->makeBid($lotId, $lead_auto_bid->user_id, min(AutoBid::calcAuctionPrice($lotId), $lead_auto_bid->price), true, false);

                // Послать уведомление, что было сделано 2 ставки
                $params = [
                    'lot'          => $lot,
                    'bids'         => [$n2, $n],
                    //'userLink' => User::model()->findByPk($event->params['owner'])->getLink()
                ];
                $ntf = new Notification($lot->owner, $params, Notification::TYPE_ACTIVE_LOTS_BIDS);
                $ntf->send();

                RAjax::error([
                    'type'    => 'rebid',
                    'price'   => $calc_price,
                    'message' => 'Ваша ставка была перебита',
                ]);
            }

            RAjax::success(array(
                'bidId' => $n
            ));
        } else {
            RAjax::error(array(
                'type' => 'MAX_SET',
                'message' => 'Максимальная ставка переустановлена'
            ));
        }
    }

}
