<?php
defined('ABSPATH') || exit;

final class WM_Order_Mapper {
    public static function from_order(WC_Order $order, array $options): array {
        $products = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $qty = max(1, (int)$item->get_quantity());
            $weight = wc_get_weight((float)$product->get_weight(), 'g');
            if ($weight <= 0) return ['error'=>new WP_Error('missing_weight', sprintf('وزن محصول «%s» ثبت نشده است.', $item->get_name()), ['status'=>422])];
            $products[] = ['count'=>$qty,'discount'=>(int)round((float)$item->get_subtotal()-(float)$item->get_total()),'price'=>(int)round((float)$order->get_line_total($item, false, false)/$qty),'title'=>$item->get_name(),'weight'=>(int)round($weight),'product_id'=>null];
        }
        if (!$products) return ['error'=>new WP_Error('empty_order', 'سفارش محصول قابل ارسال ندارد.', ['status'=>422])];
        $province = absint($options['province_code'] ?? $order->get_meta('_tapin_province_code'));
        $city = absint($options['city_code'] ?? $order->get_meta('_tapin_city_code'));
        if (!$province || !$city) {
            $matched=self::match_location($order); if(!is_wp_error($matched)){ $province=$matched['province_code'];$city=$matched['city_code'];$order->update_meta_data('_tapin_province_code',$province);$order->update_meta_data('_tapin_city_code',$city);$order->save(); }
        }
        if (!$province || !$city) return ['error'=>new WP_Error('missing_tapin_location', 'استان و شهر تاپین برای این سفارش مشخص نشده است.', ['status'=>422])];
        return ['payload'=>[
            'register_type'=>1,'shop_id'=>WM_Settings::get('tapin_shop_id'),'address'=>trim($order->get_shipping_address_1().' '.$order->get_shipping_address_2()),
            'city_code'=>$city,'province_code'=>$province,'description'=>$order->get_customer_note() ?: null,'email'=>$order->get_billing_email() ?: null,
            'employee_code'=>-1,'first_name'=>$order->get_shipping_first_name() ?: $order->get_billing_first_name(),'last_name'=>$order->get_shipping_last_name() ?: $order->get_billing_last_name(),
            'mobile'=>$order->get_billing_phone(),'phone'=>null,'postal_code'=>$order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'pay_type'=>absint($options['pay_type'] ?? 1),'order_type'=>absint($options['order_type'] ?? WM_Settings::get('tapin_order_type','1')),
            'box_id'=>absint($options['box_id'] ?? WM_Settings::get('tapin_box_id')),'package_weight'=>absint($options['package_weight'] ?? WM_Settings::get('tapin_package_weight','100')),
            'manual_id'=>(string)$order->get_id(),'has_insurance'=>true,'content_type'=>absint($options['content_type'] ?? 1),'pre_paid_price'=>0,'products'=>$products,
        ]];
    }
    private static function match_location(WC_Order $order) {
        $data=WM_Tapin::locations(); if(is_wp_error($data))return $data;
        $state_code=$order->get_shipping_state()?:$order->get_billing_state(); $states=WC()->countries->get_states('IR'); $state=self::normalize($states[$state_code]??$state_code);
        $city=self::normalize($order->get_shipping_city()?:$order->get_billing_city());
        foreach((array)($data['entries']??[]) as $province){ if(self::normalize((string)($province['title']??''))!==$state)continue; foreach((array)($province['cities']??[]) as $candidate){if(self::normalize((string)($candidate['title']??''))===$city)return ['province_code'=>(int)$province['code'],'city_code'=>(int)$candidate['code']];}}
        return new WP_Error('location_not_matched','نام شهر یا استان با فهرست واقعی تاپین تطبیق پیدا نکرد.',['status'=>422]);
    }
    private static function normalize(string $value): string { return trim(preg_replace('/\s+/u',' ',str_replace(['ي','ك','ۀ','ة'],['ی','ک','ه','ه'],mb_strtolower($value)))); }
}
