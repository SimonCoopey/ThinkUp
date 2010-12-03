<?php
/**
 *
 * ThinkUp/webapp/plugins/hellothinkup/model/class.HelloThinkUpPlugin.php
 *
 * Copyright (c) 2009-2010 Gina Trapani, Guillaume Boudreau, Mark Wilkie
 *
 * LICENSE:
 *
 * This file is part of ThinkUp (http://thinkupapp.com).
 *
 * ThinkUp is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any
 * later version.
 *
 * ThinkUp is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with ThinkUp.  If not, see
 * <http://www.gnu.org/licenses/>.
 */
/**
 * @author Gina Trapani <ginatrapani[at]gmail[dot]com>
 * @author Guillaume Boudreau <gboudreau[at]pommepause[dot]com>
 * @author Mark Wilkie <mark[at]bitterpill[dot]org>
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2010 Gina Trapani, Guillaume Boudreau, Mark Wilkie
 */
class HelloThinkUpPlugin implements CrawlerPlugin, PostDetailPlugin {

    public function renderConfiguration($owner) {
        $controller = new HelloThinkUpPluginConfigurationController($owner, 'hellothinkup');
        return $controller->go();
    }

    public function crawl() {
        //echo "HelloThinkUp crawler plugin is running now.";
        /**
        * When crawling, make sure you only work on objects the current Owner has access to.
        *
        * Example:
        *
        *	$od = DAOFactory::getDAO('OwnerDAO');
        *	$oid = DAOFactory::getDAO('OwnerInstanceDAO');
        *
        * $current_owner = $od->getByEmail(Session::getLoggedInUser());
        *
        * $instances = [...]
        * foreach ($instances as $instance) {
        *	    if (!$oid->doesOwnerHaveAccess($current_owner, $instance)) {
        *	        // Owner doesn't have access to this instance; let's not crawl it.
        *	        continue;
        *	    }
        *	    [...]
        * }
        *
        */
    }

    public function getPostDetailMenu($post) {
        $template_path = Utils::getPluginViewDirectory('hellothinkup').'hellothinkup.inline.view.tpl';
        $menus = array();

        //Define a menu (collection of menu items)
        $hello_menu_1 = new Menu('Hello ThinkUp 1');
        //Define a menu item
        $hello_menu_item_1 = new MenuItem("replies_1", "Replies 1", "", $template_path);
        //Define a dataset to be displayed when that menu item is selected
        $hello_menu_item_dataset_1 = new Dataset("replies_1", 'PostDAO', "getRepliesToPost",
        array($post->post_id, $post->network, 'location') );
        //Associate dataset with menu item
        $hello_menu_item_1->addDataset($hello_menu_item_dataset_1);
        //Add menu item to menu
        $hello_menu_1->addMenuItem($hello_menu_item_1);

        //Define a menu item
        $hello_menu_item_2 = new MenuItem("replies_2", "Replies 2", "", $template_path);
        //Define a dataset to be displayed when that menu item is selected
        $hello_menu_item_dataset_2 = new Dataset("replies_2", 'PostDAO', "getRepliesToPost",
        array($post->post_id, $post->network, 'location') );
        //Associate dataset with menu item
        $hello_menu_item_2->addDataset($hello_menu_item_dataset_2);
        //Add menu item to menu
        $hello_menu_1->addMenuItem($hello_menu_item_2);

        array_push($menus, $hello_menu_1);

        return $menus;
    }
}