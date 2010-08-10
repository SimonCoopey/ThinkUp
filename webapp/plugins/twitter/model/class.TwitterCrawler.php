<?php
// require_once 'model/class.Utils.php';
/**
 *
 * ThinkUp/webapp/plugins/twitter/model/class.TwitterCrawler.php
 *
 * Copyright (c) 2009-2010 Gina Trapani
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
 * Twitter Crawler
 *
 * Retrieves tweets, replies, users, and following relationships from Twitter.com
 *
 * @TODO Complete docblocks
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2010 Gina Trapani
 * @author Gina Trapani <ginatrapani[at]gmail[dot]com>
 *
 */
class TwitterCrawler {
    var $instance;
    var $api;
    var $owner_object;
    var $user_dao;
    var $logger;
    var $config;
    

    public function __construct($instance, $api) {
        $this->instance = $instance;
        $this->api = $api;
        $this->logger = Logger::getInstance();
        $this->logger->setUsername($instance->network_username);
        $this->user_dao = DAOFactory::getDAO('UserDAO');
        $this->config = Config::getInstance();
    }

    public function fetchInstanceUserInfo() {
        // Get owner user details and save them to DB
        if ($this->api->available && $this->api->available_api_calls_for_crawler > 0) {
            $status_message = "";
            $owner_profile = str_replace("[id]", $this->instance->network_username,
            $this->api->cURL_source['show_user']);
            list($cURL_status, $twitter_data) = $this->api->apiRequest($owner_profile);

            if ($cURL_status == 200) {
                try {
                    $users = $this->api->parseXML($twitter_data);
                    foreach ($users as $user) {
                        $this->owner_object = new User($user, 'Owner Status');
                    }

                    if (isset($this->owner_object)) {
                        $status_message = 'Owner info set.';
                        $this->user_dao->updateUser($this->owner_object);

                        if (isset($this->owner_object->follower_count) && $this->owner_object->follower_count>0) {
                            $fcount_dao = DAOFactory::getDAO('FollowerCountDAO');
                            $fcount_dao->insert($this->owner_object->user_id, 'twitter',
                            $this->owner_object->follower_count);
                        }

                    } else {
                        $status_message = 'Owner was not set.';
                    }
                }
                catch(Exception $e) {
                    $status_message = 'Could not parse profile XML for $this->owner_object->username';
                }
            } else {
                $status_message = 'cURL status is not 200';
            }
            $this->logger->logStatus($status_message, get_class($this));
        }
    }

    public function fetchSearchResults($term) {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }
        if (isset($this->owner_object)) {
            $continue_fetching = true;
            $page = 1;
            while ($continue_fetching) {
                $search_results = $this->api->cURL_source['search']."?q=".urlencode($term).
            "&result_type=recent&rpp=100&page=".$page;
                list($cURL_status, $twitter_data) = $this->api->apiRequest($search_results, null, false);
                if ($cURL_status == 200) {
                    $tweets = $this->api->parseJSON($twitter_data);
                    $pd = DAOFactory::getDAO('PostDAO');
                    $count = 0;
                    foreach ($tweets as $tweet) {
                        $tweet['network'] = 'twitter';

                        if ($pd->addPost($tweet) > 0) {
                            $count = $count + 1;
                            $this->processTweetURLs($tweet);

                            //don't update owner info from reply
                            if ($tweet['user_id'] != $this->owner_object->user_id) {
                                $u = new User($tweet, 'mentions');
                                $this->user_dao->updateUser($u);
                            }
                        }
                    }
                    $this->logger->logStatus(count($tweets)." tweet(s) found and $count saved", get_class($this));
                    if ( $count == 0 ) { // all tweets on the page were already saved
                        //Stop fetching when more tweets have been retrieved than were saved b/c they already existed
                        $continue_fetching = false;
                    }
                    $page = $page+1;
                } else {
                    $this->logger->logStatus("cURL status $cURL_status", get_class($this));
                    $continue_fetching = false;
                }
            }
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    public function fetchInstanceUserTweets() {

        // aju
        $this->logger->logStatus("***in fetchInstanceUserTweets", get_class($this));
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }
        if (isset($this->owner_object)) {
            // Get owner's tweets
            $status_message = "";
            $got_latest_page_of_tweets = false;
            $continue_fetching = true;

            while ($this->api->available && $this->api->available_api_calls_for_crawler > 0
            && $this->owner_object->post_count > $this->instance->total_posts_in_system && $continue_fetching) {

                $recent_tweets = str_replace("[id]", $this->owner_object->username,
                $this->api->cURL_source['user_timeline']);
                $args = array();
                $args["count"] = 200;
                $args["include_rts"] = "true";
                $last_page_of_tweets = round($this->api->archive_limit / 200) + 1;

                //set page and since_id params for API call
                if ($got_latest_page_of_tweets
                && $this->owner_object->post_count != $this->instance->total_posts_in_system
                && $this->instance->total_posts_in_system < $this->api->archive_limit) {
                    if ($this->instance->last_page_fetched_tweets < $last_page_of_tweets)
                    $this->instance->last_page_fetched_tweets = $this->instance->last_page_fetched_tweets + 1;
                    else {
                        $continue_fetching = false;
                        $this->instance->last_page_fetched_tweets = 0;
                    }
                    $args["page"] = $this->instance->last_page_fetched_tweets;

                } else {
                    if (!$got_latest_page_of_tweets && $this->instance->last_post_id > 0)
                    $args["since_id"] = $this->instance->last_post_id;
                }

                list($cURL_status, $twitter_data) = $this->api->apiRequest($recent_tweets, $args);
                if ($cURL_status == 200) {
                    # Parse the XML file
                    try {
                        $count = 0;
                        $tweets = $this->api->parseXML($twitter_data);

                        $pd = DAOFactory::getDAO('PostDAO');
                        foreach ($tweets as $tweet) {
                            $tweet['network'] = 'twitter';

                            if ($pd->addPost($tweet, $this->owner_object, $this->logger) > 0) {
                                $count = $count + 1;
                                $this->instance->total_posts_in_system = $this->instance->total_posts_in_system + 1;

                                //expand and insert links contained in tweet
                                $this->processTweetURLs($tweet);

                            }
                            if ($tweet['post_id'] > $this->instance->last_post_id)
                            $this->instance->last_post_id = $tweet['post_id'];

                        }
                        $status_message .= count($tweets)." tweet(s) found and $count saved";
                        $this->logger->logStatus($status_message, get_class($this));
                        $status_message = "";

                        //if you've got more than the Twitter API archive limit, stop looking for more tweets
                        if ($this->instance->total_posts_in_system >= $this->api->archive_limit) {
                            $this->instance->last_page_fetched_tweets = 1;
                            $continue_fetching = false;
                            $status_message = "More than Twitter cap of ".$this->api->archive_limit.
                        " already in system, moving on.";
                            $this->logger->logStatus($status_message, get_class($this));
                            $status_message = "";
                        }


                        if ($this->owner_object->post_count == $this->instance->total_posts_in_system)
                        $this->instance->is_archive_loaded_tweets = true;

                        $status_message .= $this->instance->total_posts_in_system." in system; ".
                        $this->owner_object->post_count." by owner";
                        $this->logger->logStatus($status_message, get_class($this));
                        $status_message = "";

                    }
                    catch(Exception $e) {
                        $status_message = 'Could not parse tweet XML for $this->network_username';
                        $this->logger->logStatus($status_message, get_class($this));
                        $status_message = "";

                    }

                    $got_latest_page_of_tweets = true;
                }
            }

            if ($this->owner_object->post_count == $this->instance->total_posts_in_system)
            $status_message .= "All of ".$this->owner_object->username.
            "'s tweets are in the system; Stopping tweet fetch.";


            $this->logger->logStatus($status_message, get_class($this));
            $status_message = "";
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    private function processTweetURLs($tweet) {
        $ld = DAOFactory::getDAO('LinkDAO');

        $urls = Post::extractURLs($tweet['post_text']);
        foreach ($urls as $u) {
            //if it's an image (Twitpic/Twitgoo/Yfrog/Flickr for now)
            //insert direct path to thumb as expanded url, otherwise, just expand
            //set defaults
            $is_image = 0;
            $title = '';
            $eurl = '';
            //TODO Abstract out this image thumbnail link expansion into an Image Thumbnail plugin
            //modeled after the Flickr Thumbnails plugin
            if (substr($u, 0, strlen('http://twitpic.com/')) == 'http://twitpic.com/') {
                $eurl = 'http://twitpic.com/show/thumb/'.substr($u, strlen('http://twitpic.com/'));
                $is_image = 1;
            } elseif (substr($u, 0, strlen('http://yfrog.com/')) == 'http://yfrog.com/') {
                $eurl = $u.'.th.jpg';
                $is_image = 1;
            } elseif (substr($u, 0, strlen('http://twitgoo.com/')) == 'http://twitgoo.com/') {
                $eurl = 'http://twitgoo.com/show/thumb/'.substr($u, strlen('http://twitgoo.com/'));
                $is_image = 1;
            } elseif (substr($u, 0, strlen('http://flic.kr/')) == 'http://flic.kr/') {
                $is_image = 1;
            }
            if ($ld->insert($u, $eurl, $title, $tweet['post_id'], 'twitter', $is_image)) {
                $this->logger->logStatus("Inserted ".$u." (".$eurl.", ".$is_image."), into links table",
                get_class($this));
            } else {
                $this->logger->logStatus("Did NOT insert ".$u." (".$eurl.") into links table", get_class($this));
            }
        }
    }

    private function fetchAndAddTweetRepliedTo($tid, $pd) {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            //fetch tweet from Twitter and add to DB
            $status_message = "";
            $tweet_deets = str_replace("[id]", $tid, $this->api->cURL_source['show_tweet']);
            list($cURL_status, $twitter_data) = $this->api->apiRequest($tweet_deets);

            if ($cURL_status == 200) {
                try {
                    $tweets = $this->api->parseXML($twitter_data);
                    foreach ($tweets as $tweet) {
                        if ($pd->addPost($tweet, $this->owner_object, $this->logger) > 0) {
                            $status_message = 'Added replied to tweet ID '.$tid." to database.";
                            //expand and insert links contained in tweet
                            $this->processTweetURLs($tweet);
                        }
                    }
                }
                catch(Exception $e) {
                    $status_message = 'Could not parse tweet XML for $id';
                }
            } elseif ($cURL_status == 404 || $cURL_status == 403) {
                try {
                    $e = $this->api->parseError($twitter_data);
                    $ped = DAOFactory::getDAO('PostErrorDAO');
                    $ped->insertError($tid, 'twitter', $cURL_status, $e['error'], $this->owner_object->user_id);
                    $status_message = 'Error saved to tweets.';
                }
                catch(Exception $e) {
                    $status_message = 'Could not parse tweet XML for $tid';
                }
            }
            $this->logger->logStatus($status_message, get_class($this));
            $status_message = "";
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    public function fetchInstanceUserMentions() {

        // aju
        $this->logger->logStatus("***in fetchInstanceUserMentions", get_class($this));
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            $status_message = "";
            // Get owner's mentions
            if ($this->api->available_api_calls_for_crawler > 0) {
                $got_newest_mentions = false;
                $continue_fetching = true;

                while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue_fetching) {
                    # Get the most recent mentions
                    $mentions = $this->api->cURL_source['mentions'];
                    $args = array();
                    $args['count'] = 200;
                    $args['include_rts']='true';

                    if ($got_newest_mentions) {
                        $this->last_page_fetched_mentions++;
                        $args['page'] = $this->last_page_fetched_mentions;
                    }

                    list($cURL_status, $twitter_data) = $this->api->apiRequest($mentions, $args);
                    if ($cURL_status > 200) {
                        $continue_fetching = false;
                    } else {
                        try {
                            $count = 0;
                            $tweets = $this->api->parseXML($twitter_data);
                            if (count($tweets) == 0 && $got_newest_mentions) {# you're paged back and no new tweets
                                $this->last_page_fetched_mentions = 1;
                                $continue_fetching = false;
                                $this->instance->is_archive_loaded_mentions = true;
                                $status_message = 'Paged back but not finding new mentions; moving on.';
                                $this->logger->logStatus($status_message, get_class($this));
                                $status_message = "";
                            }


                            $pd = DAOFactory::getDAO('PostDAO');
                            if (!isset($recentTweets)) {
                                $recentTweets = $pd->getAllPosts($this->owner_object->user_id, 'twitter', 100);
                            }
                            $count = 0;
                            foreach ($tweets as $tweet) {
                                // Figure out if the mention is a retweet
                                if (RetweetDetector::isRetweet($tweet['post_text'], $this->owner_object->username)) {
                                    $this->logger->logStatus("Retweet found, ".substr($tweet['post_text'], 0, 50).
                                    "... ", get_class($this));
                                    $originalTweetId = RetweetDetector::detectOriginalTweet($tweet['post_text'],
                                    $recentTweets);
                                    if ($originalTweetId != false) {
                                        $tweet['in_retweet_of_post_id'] = $originalTweetId;
                                        $this->logger->logStatus("Retweet original status ID found: ".$originalTweetId,
                                        get_class($this));
                                    }
                                }

                                if ($pd->addPost($tweet, $this->owner_object, $this->logger) > 0) {
                                    $count++;
                                    //expand and insert links contained in tweet
                                    $this->processTweetURLs($tweet);
                                    if ($tweet['user_id'] != $this->owner_object->user_id) {
                                        //don't update owner info from reply
                                        $u = new User($tweet, 'mentions');
                                        $this->user_dao->updateUser($u);
                                    }

                                }

                            }
                            $status_message .= count($tweets)." mentions found and $count saved";
                            $this->logger->logStatus($status_message, get_class($this));
                            $status_message = "";

                            $got_newest_mentions = true;

                            $this->logger->logStatus($status_message, get_class($this));
                            $status_message = "";

                            if ($got_newest_mentions && $this->instance->is_archive_loaded_replies) {
                                $continue_fetching = false;
                                $status_message .= 'Retrieved newest mentions; Archive loaded; Stopping reply fetch.';
                                $this->logger->logStatus($status_message, get_class($this));
                                $status_message = "";
                            }

                        }
                        catch(Exception $e) {
                            $status_message = 'Could not parse mentions XML for $this->owner_object->username';
                            $this->logger->logStatus($status_message, get_class($this));
                            $status_message = "";
                        }
                    }

                }
            } else {
                $status_message = 'Crawler API error: either call limit exceeded or API returned an error.';
            }

            $this->logger->logStatus($status_message, get_class($this));
            $status_message = "";
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }


    /**
     * Retrieve recent retweets and add them to the database
     */
    public function fetchRetweetsOfInstanceUser() {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            $status_message = "";
            // Get owner's mentions
            if ($this->api->available && $this->api->available_api_calls_for_crawler > 0) {
                # Get the most recent retweets
                $rtsofme = $this->api->cURL_source['retweets_of_me'];
                list($cURL_status, $twitter_data) = $this->api->apiRequest($rtsofme);
                if ($cURL_status == 200) {
                    try {
                        $tweets = $this->api->parseXML($twitter_data);
                        foreach ($tweets as $tweet) {
                            $this->fetchStatusRetweets($tweet);
                        }
                    } catch(Exception $e) {
                        $status_message = 'Could not parse retweets_of_me XML for $this->owner_object->username';
                        $this->logger->logStatus($status_message, get_class($this));
                        $status_message = "";
                    }
                } else {
                    $status_message .= 'API returned error code '. $cURL_status;
                }
            } else {
                $status_message .= 'Crawler API error: either call limit exceeded or API returned an error.';
            }

            $this->logger->logStatus($status_message, get_class($this));
            $status_message = "";
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    /**
     * Retrieve retweets of given status
     * @param array $status
     */
    private function fetchStatusRetweets($status) {
        $status_id = $status["post_id"];
        $status_message = "";
        // Get owner's mentions
        if ($this->api->available && $this->api->available_api_calls_for_crawler > 0) {
            # Get the most recent mentions
            $rts = str_replace("[id]", $status_id, $this->api->cURL_source['retweeted_by']);
            list($cURL_status, $twitter_data) = $this->api->apiRequest($rts);
            if ($cURL_status == 200) {
                try {
                    $tweets = $this->api->parseXML($twitter_data);
                    foreach ($tweets as $tweet) {
                        $user_with_retweet = new User($tweet, 'retweets');
                        $this->fetchUserTimelineForRetweet($status, $user_with_retweet);
                    }
                } catch (Exception $e) {
                    $status_message = 'Could not parse retweeted_by XML for $this->owner_object->username';
                    $this->logger->logStatus($status_message, get_class($this));
                    $status_message = "";
                }
            } else {
                $status_message .= 'API returned error code '. $cURL_status;
            }
        } else {
            $status_message .= 'Crawler API error: either call limit exceeded or API returned an error.';
        }
        $this->logger->logStatus($status_message, get_class($this));
    }

    /**
     * Retrieve a retweeting user's timeline
     * @param array $retweeted_status
     * @param User $user_with_retweet
     */
    private function fetchUserTimelineForRetweet($retweeted_status, $user_with_retweet) {
        $retweeted_status_id = $retweeted_status["post_id"];
        $status_message = "";

        if ($this->api->available && $this->api->available_api_calls_for_crawler > 0) {
            $stream_with_retweet = str_replace("[id]", $user_with_retweet->username,
            $this->api->cURL_source['user_timeline']);
            $args = array();
            $args["count"] = 200;
            $args["include_rts"]="true";

            list($cURL_status, $twitter_data) = $this->api->apiRequest($stream_with_retweet, $args);

            if ($cURL_status == 200) {
                try {
                    $count = 0;
                    $tweets = $this->api->parseXML($twitter_data);

                    if (count($tweets) > 0) {
                        $pd = DAOFactory::getDAO('PostDAO');
                        foreach ($tweets as $tweet) {
                            if (RetweetDetector::isRetweet($tweet['post_text'], $this->owner_object->username)) {
                                $this->logger->logStatus("Retweet by ".$tweet['user_name']. " found, ".
                                substr($tweet['post_text'], 0, 50)."... ", get_class($this));
                                if ( RetweetDetector::isRetweetOfTweet($tweet["post_text"],
                                $retweeted_status["post_text"]) ){
                                    $tweet['in_retweet_of_post_id'] = $retweeted_status_id;
                                    $this->logger->logStatus("Retweet by ".$tweet['user_name']." of ".
                                    $this->owner_object->username." original status ID found: ".$retweeted_status_id,
                                    get_class($this));
                                } else {
                                    $this->logger->logStatus("Retweet by ".$tweet['user_name']." of ".
                                    $this->owner_object->username." original status ID NOT found: ".
                                    $retweeted_status["post_text"]." NOT a RT of: ". $tweet["post_text"],
                                    get_class($this));
                                }
                            }
                            if ($pd->addPost($tweet, $user_with_retweet, $this->logger) > 0) {
                                $count++;
                                //expand and insert links contained in tweet
                                $this->processTweetURLs($tweet);
                                $this->user_dao->updateUser($user_with_retweet);
                            }
                        }
                        $this->logger->logStatus(count($tweets)." tweet(s) found in usertimeline via retweet for ".
                        $user_with_retweet->username." and $count saved", get_class($this));
                    }
                } catch(Exception $e) {
                    $this->logger->logStatus($e->getMessage(), get_class($this));
                    $this->logger->logStatus('Could not parse timeline for retweets XML for '.
                    $user_with_retweet->username, get_class($this));
                }
            } elseif ($cURL_status == 401) { //not authorized to see user timeline
                //don't set API to unavailable just because a private user retweeted
                $this->api->available = true;
                $status_message .= 'Not authorized to see '.$user_with_retweet->username."'s timeline;moving on.";
            } else {
                $status_message .= 'API returned error code '. $cURL_status;
            }
        } else {
            $status_message .= 'Crawler API error: either call limit exceeded or API returned an error.';
        }
        $this->logger->logStatus($status_message, get_class($this));
    }

    private function fetchInstanceUserFollowersByIDs() {
        $continue_fetching = true;
        $status_message = "";

        while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue_fetching) {

            $args = array();
            $follower_ids = $this->api->cURL_source['followers_ids'];
            if (!isset($next_cursor)) {
                $next_cursor = -1;
            }
            $args['cursor'] = strval($next_cursor);

            list($cURL_status, $twitter_data) = $this->api->apiRequest($follower_ids, $args);

            if ($cURL_status > 200) {
                $continue_fetching = false;
            } else {
                $fd = DAOFactory::getDAO('FollowDAO');

                try {
                    $status_message = "Parsing XML. ";
                    $status_message .= "Cursor ".$next_cursor.":";
                    $ids = $this->api->parseXML($twitter_data);
                    $next_cursor = $this->api->getNextCursor();
                    $status_message .= count($ids)." follower IDs queued to update. ";
                    $this->logger->logStatus($status_message, get_class($this));
                    $status_message = "";


                    if (count($ids) == 0) {
                        $this->instance->is_archive_loaded_follows = true;
                        $continue_fetching = false;
                    }

                    $updated_follow_count = 0;
                    $inserted_follow_count = 0;
                    foreach ($ids as $id) {

                        # add/update follow relationship
                        if ($fd->followExists($this->instance->network_user_id, $id['id'], 'twitter')) {
                            //update it
                            if ($fd->update($this->instance->network_user_id, $id['id'], 'twitter',
                            Utils::getURLWithParams($follower_ids, $args)))
                            $updated_follow_count = $updated_follow_count + 1;
                        } else {
                            //insert it
                            if ($fd->insert($this->instance->network_user_id, $id['id'], 'twitter',
                            Utils::getURLWithParams($follower_ids, $args)))
                            $inserted_follow_count = $inserted_follow_count + 1;
                        }
                    }

                    $status_message .= "$updated_follow_count existing follows updated; ".$inserted_follow_count.
                    " new follows inserted.";
                }
                catch(Exception $e) {
                    $status_message = 'Could not parse follower ID XML for $crawler_twitter_username';
                }
                $this->logger->logStatus($status_message, get_class($this));
                $status_message = "";

            }

            $this->logger->logStatus($status_message, get_class($this));
            $status_message = "";

        }

    }

    public function fetchInstanceUserFollowers() {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            $status_message = "";
            // Get owner's followers: Page back only if more than 2% of follows are missing from database
            // See how many are missing from last run
            if ($this->instance->is_archive_loaded_follows) { //all pages have been loaded
                $this->logger->logStatus("Follower archive marked as loaded", get_class($this));

                //find out how many new follows owner has compared to what's in db
                $new_follower_count = $this->owner_object->follower_count - $this->instance->total_follows_in_system;
                $status_message = "New follower count is ".$this->owner_object->follower_count." and system has ".
                $this->instance->total_follows_in_system."; ".$new_follower_count." new follows to load";
                $this->logger->logStatus($status_message, get_class($this));

                if ($new_follower_count > 0) {
                    $this->logger->logStatus("Fetching follows via IDs", get_class($this));
                    $this->fetchInstanceUserFollowersByIDs();
                }
            } else {
                $this->logger->logStatus("Follower archive is not loaded; fetch should begin.", get_class($this));
            }

            # Fetch follower pages
            $continue_fetching = true;
            while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue_fetching
            && !$this->instance->is_archive_loaded_follows) {

                $follower_ids = $this->api->cURL_source['followers'];
                $args = array();
                if (!isset($next_cursor))
                $next_cursor = -1;
                $args['cursor'] = strval($next_cursor);

                list($cURL_status, $twitter_data) = $this->api->apiRequest($follower_ids, $args);

                if ($cURL_status > 200) {
                    $continue_fetching = false;
                } else {
                    $fd = DAOFactory::getDAO('FollowDAO');;

                    try {
                        $status_message = "Parsing XML. ";
                        $status_message .= "Cursor ".$next_cursor.":";
                        $users = $this->api->parseXML($twitter_data);
                        $next_cursor = $this->api->getNextCursor();
                        $status_message .= count($users)." followers queued to update. ";
                        $this->logger->logStatus($status_message, get_class($this));
                        $status_message = "";

                        if (count($users) == 0)
                        $this->instance->is_archive_loaded_follows = true;

                        $updated_follow_count = 0;
                        $inserted_follow_count = 0;
                        foreach ($users as $u) {
                            $utu = new User($u, 'Follows');
                            $this->user_dao->updateUser($utu);

                            # add/update follow relationship
                            if ($fd->followExists($this->instance->network_user_id, $utu->user_id, 'twitter')) {
                                //update it
                                if ($fd->update($this->instance->network_user_id, $utu->user_id, 'twitter',
                                Utils::getURLWithParams($follower_ids, $args)))
                                $updated_follow_count++;
                            } else {
                                //insert it
                                if ($fd->insert($this->instance->network_user_id, $utu->user_id, 'twitter',
                                Utils::getURLWithParams($follower_ids, $args)))
                                $inserted_follow_count++;
                            }
                        }

                        $status_message .= "$updated_follow_count existing follows updated; ".$inserted_follow_count.
                    " new follows inserted.";
                    }
                    catch(Exception $e) {
                        $status_message = 'Could not parse followers XML for $crawler_twitter_username';
                    }
                    $this->logger->logStatus($status_message, get_class($this));
                    $status_message = "";

                }

                $this->logger->logStatus($status_message, get_class($this));
                $status_message = "";
            }
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }

    }

    public function fetchInstanceUserFriends() {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            $fd = DAOFactory::getDAO('FollowDAO');
            $this->instance->total_friends_in_system = $fd->countTotalFriends($this->instance->network_user_id,
            'twitter');

            if ($this->instance->total_friends_in_system
            < $this->owner_object->friend_count) {
                $this->instance->is_archive_loaded_friends = false;
                $this->logger->logStatus($this->instance->total_friends_in_system." friends in system, ".
                $this->owner_object->friend_count." friends according to Twitter; Friend archive is not loaded",
                get_class($this));
            } else {
                $this->instance->is_archive_loaded_friends = true;
                $this->logger->logStatus("Friend archive loaded", get_class($this));
            }

            $status_message = "";
            # Fetch friend pages
            $continue_fetching = true;
            while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue_fetching
            && !$this->instance->is_archive_loaded_friends) {

                $friend_ids = $this->api->cURL_source['following'];
                $args = array();
                if (!isset($next_cursor))
                $next_cursor = -1;
                $args['cursor'] = strval($next_cursor);

                list($cURL_status, $twitter_data) = $this->api->apiRequest($friend_ids, $args);

                if ($cURL_status > 200) {
                    $continue_fetching = false;
                } else {

                    try {
                        $status_message = "Parsing XML. ";
                        $status_message .= "Cursor ".$next_cursor.":";
                        $users = $this->api->parseXML($twitter_data);
                        $next_cursor = $this->api->getNextCursor();
                        $status_message .= count($users)." friends queued to update. ";
                        $this->logger->logStatus($status_message, get_class($this));
                        $status_message = "";

                        $updated_follow_count = 0;
                        $inserted_follow_count = 0;

                        if (count($users) == 0)
                        $this->instance->is_archive_loaded_friends = true;

                        foreach ($users as $u) {
                            $utu = new User($u, 'Friends');
                            $this->user_dao->updateUser($utu);

                            # add/update follow relationship
                            if ($fd->followExists($utu->user_id, $this->instance->network_user_id, 'twitter')) {
                                //update it
                                if ($fd->update($utu->user_id, $this->instance->network_user_id, 'twitter',
                                Utils::getURLWithParams($friend_ids, $args)))
                                $updated_follow_count++;
                            } else {
                                //insert it
                                if ($fd->insert($utu->user_id, $this->instance->network_user_id, 'twitter',
                                Utils::getURLWithParams($friend_ids, $args)))
                                $inserted_follow_count++;
                            }

                        }

                        $status_message .= "$updated_follow_count existing friends updated; ".$inserted_follow_count.
                    " new friends inserted.";
                    }
                    catch(Exception $e) {
                        $status_message = 'Could not parse friends XML for $crawler_twitter_username';
                    }
                    $this->logger->logStatus($status_message, get_class($this));
                    $status_message = "";

                }

                $this->logger->logStatus($status_message, get_class($this));
                $status_message = "";
            }
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    public function fetchFriendTweetsAndFriends() {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            $fd = DAOFactory::getDAO('FollowDAO');
            $pd = DAOFactory::getDAO('PostDAO');

            $continue_fetching = true;
            while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue_fetching) {
                $stale_friend = $fd->getStalestFriend($this->owner_object->user_id, 'twitter');
                if ($stale_friend != null) {
                    $this->logger->logStatus($stale_friend->username." is friend most need of update",
                    get_class($this));
                    $stale_friend_tweets = str_replace("[id]", $stale_friend->username,
                    $this->api->cURL_source['user_timeline']);
                    $args = array();
                    $args["count"] = 200;

                    if ($stale_friend->last_post_id > 0) {
                        $args['since_id'] = $stale_friend->last_post_id;
                    }

                    list($cURL_status, $twitter_data) = $this->api->apiRequest($stale_friend_tweets, $args);

                    if ($cURL_status == 200) {
                        try {
                            $count = 0;
                            $tweets = $this->api->parseXML($twitter_data);

                            if (count($tweets) > 0) {
                                $stale_friend_updated_from_tweets = false;
                                foreach ($tweets as $tweet) {

                                    if ($pd->addPost($tweet, $stale_friend, $this->logger) > 0) {
                                        $count++;
                                        //expand and insert links contained in tweet
                                        $this->processTweetURLs($tweet);
                                    }
                                    if (!$stale_friend_updated_from_tweets) {
                                        //Update stale_friend values here
                                        $stale_friend->full_name = $tweet['full_name'];
                                        $stale_friend->avatar = $tweet['avatar'];
                                        $stale_friend->location = $tweet['location'];
                                        $stale_friend->description = $tweet['description'];
                                        $stale_friend->url = $tweet['url'];
                                        $stale_friend->is_protected = $tweet['is_protected'];
                                        $stale_friend->follower_count = $tweet['follower_count'];
                                        $stale_friend->friend_count = $tweet['friend_count'];
                                        $stale_friend->post_count = $tweet['post_count'];
                                        $stale_friend->joined = date_format(date_create($tweet['joined']), "Y-m-d H:i:s");

                                        if ($tweet['post_id'] > $stale_friend->last_post_id) {
                                            $stale_friend->last_post_id = $tweet['post_id'];
                                        }
                                        $this->user_dao->updateUser($stale_friend);
                                        $stale_friend_updated_from_tweets = true;
                                    }
                                }
                            } else {
                                $this->fetchAndAddUser($stale_friend->user_id, "Friends");
                            }

                            $this->logger->logStatus(count($tweets)." tweet(s) found for ".$stale_friend->username.
                            " and ". $count." saved", get_class($this));
                        }
                        catch(Exception $e) {
                            $this->logger->logStatus('Could not parse friends XML for $stale_friend->username',
                            get_class($this));
                        }
                        $this->fetchUserFriendsByIDs($stale_friend->user_id, $fd);
                    } elseif ($cURL_status == 401 || $cURL_status == 404) {
                        try {
                            $e = $this->api->parseError($twitter_data);
                            $ued = DAOFactory::getDAO('UserErrorDAO');
                            $ued->insertError($stale_friend->user_id, $cURL_status, $e['error'],
                            $this->owner_object->user_id, 'twitter');
                            $this->logger->logStatus('User error saved', get_class($this));
                        }
                        catch(Exception $e) {
                            $this->logger->logStatus('Could not parse timeline error for $stale_friend->username',
                            get_class($this));
                        }
                    }
                } else {
                    $this->logger->logStatus('No friend staler than 1 day', get_class($this));
                    $continue_fetching = false;
                }
            }
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    public function fetchStrayRepliedToTweets() {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            $pd = DAOFactory::getDAO('PostDAO');
            $strays = $pd->getStrayRepliedToPosts($this->owner_object->user_id, $this->owner_object->network);
            $status_message = count($strays).' stray replied-to tweets to load for user ID '.$this->owner_object->user_id .
        ' on '.$this->owner_object->network;
            $this->logger->logStatus($status_message, get_class($this));

            foreach ($strays as $s) {
                if ($this->api->available && $this->api->available_api_calls_for_crawler > 0)
                $this->fetchAndAddTweetRepliedTo($s['in_reply_to_post_id'], $pd);
            }
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    public function fetchUnloadedFollowerDetails() {
        if (!isset($this->owner_object)) {
            $this->fetchInstanceUserInfo();
        }

        if (isset($this->owner_object)) {
            $fd = DAOFactory::getDAO('FollowDAO');
            $strays = $fd->getUnloadedFollowerDetails($this->owner_object->user_id, 'twitter');
            $status_message = count($strays).' unloaded follower details to load.';
            $this->logger->logStatus($status_message, get_class($this));

            foreach ($strays as $s) {
                if ($this->api->available && $this->api->available_api_calls_for_crawler > 0)
                $this->fetchAndAddUser($s['follower_id'], "Follower IDs");
            }
        } else {
            $this->logger->logStatus("Cannot fetch search results; Owner object has not been set.", get_class($this));
        }
    }

    private function fetchUserFriendsByIDs($uid, $fd) {
        $continue_fetching = true;
        $status_message = "";

        while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue_fetching) {

            $args = array();
            $friend_ids = $this->api->cURL_source['following_ids'];
            if (!isset($next_cursor)) {
                $next_cursor = -1;
            }
            $args['cursor'] = strval($next_cursor);
            $args['user_id'] = strval($uid);

            list($cURL_status, $twitter_data) = $this->api->apiRequest($friend_ids, $args);

            if ($cURL_status > 200) {
                $continue_fetching = false;
            } else {

                try {

                    $status_message = "Parsing XML. ";
                    $status_message .= "Cursor ".$next_cursor.":";
                    $ids = $this->api->parseXML($twitter_data);
                    $next_cursor = $this->api->getNextCursor();
                    $status_message .= count($ids)." friend IDs queued to update. ";
                    $this->logger->logStatus($status_message, get_class($this));
                    $status_message = "";


                    if (count($ids) == 0)
                    $continue_fetching = false;

                    $updated_follow_count = 0;
                    $inserted_follow_count = 0;
                    foreach ($ids as $id) {

                        # add/update follow relationship
                        if ($fd->followExists($id['id'], $uid, 'twitter')) {
                            //update it
                            if ($fd->update($id['id'], $uid, 'twitter', Utils::getURLWithParams($friend_ids, $args)))
                            $updated_follow_count++;
                        } else {
                            //insert it
                            if ($fd->insert($id['id'], $uid, 'twitter', Utils::getURLWithParams($friend_ids, $args)))
                            $inserted_follow_count++;
                        }
                    }

                    $status_message .= "$updated_follow_count existing follows updated; ".$inserted_follow_count.
                    " new follows inserted.";
                }
                catch(Exception $e) {
                    $status_message = 'Could not parse follower ID XML for $uid';
                }
                $this->logger->logStatus($status_message, get_class($this));
                $status_message = "";

            }

            $this->logger->logStatus($status_message, get_class($this));
            $status_message = "";
        }
    }

    private function fetchAndAddUser($fid, $source) {
        //fetch user from Twitter and add to DB
        $status_message = "";
        $u_deets = str_replace("[id]", $fid, $this->api->cURL_source['show_user']);
        list($cURL_status, $twitter_data) = $this->api->apiRequest($u_deets);

        if ($cURL_status == 200) {
            try {
                $user_arr = $this->api->parseXML($twitter_data);
                $user = new User($user_arr[0], $source);
                $this->user_dao->updateUser($user);
                $status_message = 'Added/updated user '.$user->username." in database";
            }
            catch(Exception $e) {
                $status_message = 'Could not parse tweet XML for $uid';
                // aju needed to add the following to prevent looping -- see issue #365
                $ued = DAOFactory::getDAO('UserErrorDAO');
                $ued->insertError($fid, $cURL_status, 'Could not parse tweet XML for $uid',
                  $this->owner_object->user_id, 'twitter');
                $status_message = 'User error saved.';
            }
        } elseif ($cURL_status == 404) {
            try {
                $e = $this->api->parseError($twitter_data);
                $ued = DAOFactory::getDAO('UserErrorDAO');
                $ued->insertError($fid, $cURL_status, $e['error'], $this->owner_object->user_id, 'twitter');
                $status_message = 'User error saved.';

            }
            catch(Exception $e) {
                // aju - re: issue #365, is same addition needed here?
                $status_message = 'Could not parse tweet XML for $uid';
            }

        }
        $this->logger->logStatus($status_message, get_class($this));
        $status_message = "";

    }

    // For each API call left, grab oldest follow relationship, check if it exists, and update table
    public function cleanUpFollows() {
      
        // aju
        $this->logger->logStatus("****working on cleanUpFollows****", get_class($this));
        
        $fd = DAOFactory::getDAO('FollowDAO');
        $continue_fetching = true;
        while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue_fetching) {

            $oldfollow = $fd->getOldestFollow('twitter');

            if ($oldfollow != null) {

                $friendship_call = $this->api->cURL_source['show_friendship'];
                $args = array();
                $args["source_id"] = $oldfollow["followee_id"];
                $args["target_id"] = $oldfollow["follower_id"];

                list($cURL_status, $twitter_data) = $this->api->apiRequest($friendship_call, $args);

                if ($cURL_status == 200) {
                    try {
                        $friendship = $this->api->parseXML($twitter_data);
                        if ($friendship['source_follows_target'] == 'true')
                        $fd->update($oldfollow["followee_id"], $oldfollow["follower_id"], 'twitter',
                        Utils::getURLWithParams($friendship_call, $args));
                        else
                        $fd->deactivate($oldfollow["followee_id"], $oldfollow["follower_id"], 'twitter',
                        Utils::getURLWithParams($friendship_call, $args));

                        if ($friendship['target_follows_source'] == 'true')
                        $fd->update($oldfollow["follower_id"], $oldfollow["followee_id"], 'twitter',
                        Utils::getURLWithParams($friendship_call, $args));
                        else
                        $fd->deactivate($oldfollow["follower_id"], $oldfollow["followee_id"], 'twitter',
                        Utils::getURLWithParams($friendship_call, $args));


                    }
                    catch(Exception $e) {
                        $status_message = 'Could not parse friendship XML';
                    }
                } else {
                    $continue_fetching = false;
                }
            } else {
                $continue_fetching = false;
            }
        }
    }
    
    /**
     * This method, and the two supporting private methods 'maintFavsFetch' and 'archivingFavsFetch', provide the
     * primary crawler functionality for adding the user's favorites to the database.   
     * For a given user, the process starts in 'archiving mode', by 
     * working forwards from the last (oldest) page of tweets to the newest.  This archiving crawl
     * is only done once.  The crawler tries to do this all in one go, but if it exhausts the available API count,
     * it will continue where it left off in the next run.
     * Then, when page 1 is reached in archiving mode, the crawler goes into 'maintenance mode' and works 
     * backwards from then on.  It first pages back until
     * it has reached the last fav it previously processed.  Then it searches back N more pages to catch any older tweets
     * that were fav'd out of chronological order, where N is determined by: $THINKUP_CFG['tfavs_older_pages'].
     * The bookkeeping for these two crawler stages is maintained in the in tu_instances entry for the user.
     * 
     * Recently, the twitter favorites API has developed some bugs that need to be worked around.  The comments below
     * provide more detail, but in a nutshell, these methods can not currently use information from twitter to 
     * calculate loop termination (so a bit more work may be done than necessary), and do not currently remove un-fav'd
     * tweets from the database.  Hopefully these API issues will be fixed by twitter in future.
     * @author Amy Unruh
     */
   public function fetchInstanceFavorites() {

     $status_message = "";
     // todo - can we get this from API?
     $page_size = 20; // number of favs per page retrieved from the API call

     $this->logger->logStatus("****working on favorites****", get_class($this));

     try {
       $last_favorites_count = $this->instance->favorites_profile;
       $this->logger->logStatus("last favs count: $last_favorites_count", get_class($this));
       $last_page_fetched_favorites = $this->instance->last_page_fetched_favorites;
       $last_fav_id = $this->instance->last_favorite_id;
       $curr_favs_count = $this->owner_object->favorites_count;
       $this->logger->logStatus("curr favs count: $curr_favs_count", get_class($this));

       $last_page_of_favs = round($this->api->archive_limit / $page_size) + 1;

       if ($last_page_fetched_favorites == "") {
         $last_page_fetched_favorites = 0;
       }
       $this->logger->logStatus("got last_page_fetched_favorites: $last_page_fetched_favorites", 
         get_class($this));
       if ($last_fav_id == "") {
         $last_fav_id = 0;
       }

       // the owner favs count, from twitter, is currently unreliable and may be less than the actual number of favs,
       // by a large margin.  So, we still go ahead and calculate the number of 'missing' tweets based on this info, 
       // but currently do not use it for fetch loop termination.
       $this->logger->logStatus("owner favs: " . $this->owner_object->favorites_count . ", instance owner favs in system: " . 
         $this->instance->owner_favs_in_system, get_class($this));
       $favs_missing = $this->owner_object->favorites_count - $this->instance->owner_favs_in_system;
       $this->logger->logStatus("favs missing: $favs_missing", get_class($this));

       // figure out if we're in 'archiving' or 'maintenance' mode, via # of last_page_fetched_favorites
       $mode = 0; // default is archving/first-fetch
       if ($last_page_fetched_favorites == 1) {
         $mode = 1; // we are in maint. mode
         $new_favs_to_add = $favs_missing;
         $this->logger->logStatus("new favs to add/missing: $new_favs_to_add", get_class($this));
         $mpage = 1;
         $starting_fav_id = $last_fav_id;
       }
       else {
         // we are in archiving mode.
         $new_favs_to_add = $curr_favs_count - $last_favorites_count;
         $this->logger->logStatus("new favs to add: $new_favs_to_add", get_class($this));

         // figure out start page based on where we left off last time, and how many favs added since then
         $extra_pages = ceil($new_favs_to_add / $page_size);
         $this->logger->logStatus("extra pages: $extra_pages", get_class($this));
         if ($last_page_fetched_favorites == 0) {
           // if at first fetch
           $last_page_fetched_favorites = $extra_pages + 1;
         }
         else {
           $last_page_fetched_favorites += $extra_pages;
         }
         if ($last_page_fetched_favorites > $last_page_of_favs) {
           $last_page_fetched_favorites = $last_page_of_favs + 1;
         }
       }

       $status_message = "total last favs count: $last_favorites_count" . 
         ", last page fetched: $last_page_fetched_favorites, last fav id: $last_fav_id";
       $this->logger->logStatus($status_message, get_class($this));
       $this->logger->logStatus("current favs count: $curr_favs_count" . 
         ", new favs to add: $new_favs_to_add, last page of favs: $last_page_of_favs, mode: $mode", 
         get_class($this));

       $continue = true;
       $fcount = 0;
       $older_favs_smode = false;
       $stop_page = 0;

       $status_message = "in fetchInstanceFavorites: API available: " . $this->api->available . ", avail for crawler: " .
         $this->api->available_api_calls_for_crawler;
       $this->logger->logStatus($status_message, get_class($this));

       while ($this->api->available && $this->api->available_api_calls_for_crawler > 0 && $continue) { 

         if ($mode != 0) { // in maintenance, not archiving mode
           if ($new_favs_to_add == 0) {
             //then done;
             $continue = false;
           }
           else {
             list($fcount, $mpage, $older_favs_smode, $stop_page, $new_favs_to_add, $last_fav_id, 
               $last_page_fetched_favorites, $continue) = 
             $this->maintFavsFetch ($starting_fav_id, $fcount, $mpage, $older_favs_smode, $stop_page, 
               $new_favs_to_add, $last_fav_id, $last_page_fetched_favorites, $continue);
           }
         }
         else { // mode 0 -- archiving mode

           list($fcount, $last_fav_id, $last_page_fetched_favorites, $continue) = 
             $this->archivingFavsFetch($fcount, $last_fav_id, $last_page_fetched_favorites, $continue);
         } 

       } // end while
     }
     catch (Exception $e) {
       $this->logger->logStatus("$e", get_class($this));
       return false;
     }
     // update necessary instance fields
     $this->logger->logStatus("new_favs_to_add: $new_favs_to_add, fcount: $fcount", get_class($this));
     $this->logger->logStatus("new 'last fav id': $last_fav_id", get_class($this));

     $this->instance->last_favorite_id = $last_fav_id;
     $this->instance->last_page_fetched_favorites =$last_page_fetched_favorites;
     $this->instance->favorites_profile = $curr_favs_count;
     return true;

   } // end fetchInstanceFavorites
    

    /**
     * maintFavsFetch implements the core of the crawler's 'maintenance fetch' for favs.  It goes into this mode
     * after the initial archving process.  In maintenance mode the crawler is just looking for new favs. It searches 
     * backwards until it finds the last-stored fav, then searches further back to find any older tweets that were favorited
     * unchronologically (as might happen if the user were looking back through a particular account's timeline).  The number 
     * of such pages to search back through is set here: $THINKUP_CFG['tfavs_older_pages'].
     */
    private function maintFavsFetch ($starting_fav_id, $fcount, $mpage, $older_favs_smode, $stop_page, 
        $new_favs_to_add, $last_fav_id, $last_page_fetched_favorites, $continue) {
      
      // $this->logger->logStatus("in maintFavsFetch", get_class($this));

      $status_message = "";
      $older_favs_pages = 2; // default, should be overridden by config file info

      list($tweets, $cURL_status, $twitter_data) = $this->getFavsPage($mpage);
      if ($cURL_status == 200) {
        try {
          if ($tweets == -1) { // should not reach this
            $this->logger->logStatus("in maintFavsFetch; could not extract any tweets from response", get_class($this));
            throw new Exception("could not extract any tweets from response");
          }
          if (sizeof($tweets) == 0) {
            // then done -- this should happen when we have run out of favs
            $this->logger->logStatus("****it appears we have run out of favorites to process", get_class($this));
            $continue = false;
          }
          else {
            $pd = DAOFactory::getDAO('FavoritePostDAO');
            foreach ($tweets as $tweet) {
              $tweet['network'] = 'twitter';

              if ($pd->addFavorite($this->owner_object->user_id, $tweet) > 0) {
                $this->logger->logStatus("found new fav: " . $tweet['post_id'], get_class($this));
                $fcount++;
                //expand and insert links contained in tweet
                $this->processTweetURLs($tweet);
                $this->logger->logStatus("fcount: $fcount", get_class($this));
              }
              else { 
                // fav was already stored, so take no action. This could happen both because some of the favs on the 
                // given page were processed last time, or because a separate process, such as a UserStream process, 
                // is also watching for and storing favs.
                // $status_message = "have already stored fav ". $tweet['post_id'];
                // $this->logger->logStatus($status_message, get_class($this));
              }

              // keep track of the highest fav id we've encountered
              if ($tweet['post_id'] > $last_fav_id) {
                $this->logger->logStatus("fav " . $tweet['post_id'] ." > $last_fav_id", get_class($this));
                $last_fav_id = $tweet['post_id'] + 0;
              }

              // if fcount reached max that we're looking for, then stop the foreach loop -- we are done
              // 23/10/10 2:15 PM arghh, this is breaking now w/ the additional API issues
//               if ($fcount >= $new_favs_to_add ) {
              // making temp change to not use the test above, while the API favs issues still exist.
              // so, currently the crawler just trawls back through all of the specified additional pages to check.
              if (false) {
                $this->logger->logStatus("done: $fcount >= $new_favs_to_add", get_class($this));
                $continue = false; 
                break;
              }
            } // end foreach
          }
        }
        catch(Exception $e) {
          $status_message = "In maintFavsFetch, could not parse or process tweet XML for " . $this->instance->network_username;
          $this->logger->logStatus($status_message, get_class($this));
          $status_message = "";
          throw new Exception($status_message);
        }

        $mpage++;
        // if have gone earlier than highest fav id from last time, then switch to 'search for older favs' mode
        if ($older_favs_smode == false) {
          // last-processed tweet
          if ($tweet['post_id'] <= $starting_fav_id) {
            $older_favs_smode = true;

            // get the number of older pages to check, from the config file.
            $conf_older_favs_pages = (int)$this->config->getValue('tfavs_older_pages');
            if (is_integer($conf_older_favs_pages) && $conf_older_favs_pages > 0) {
              $older_favs_pages = $conf_older_favs_pages;
            }
            $stop_page = $mpage + $older_favs_pages -1;
            $this->logger->logStatus("next will be searching for older favs: stop page: $stop_page, fav <= $starting_fav_id ", 
              get_class($this));
          }
        }
        else {// in older_favs_smode, check whether we should stop
          $this->logger->logStatus("in older favs search mode with stop page $stop_page", get_class($this));
          // check for terminating condition, which is (for now), that we have searched N more pages back
          // or found all the add'l tweets
          // 23/10/10 2:41 PM arghh, again making temp change due to broken API.
//           if ($mpage > $stop_page || $fcount >= $new_favs_to_add) {
          // aju temp change to not use the 'new favs to add' info while the api favs bug still exists-- it 
          // breaks things under some circs.
          // hopefully this will be fixed again by twitter at some point.         
          if ($mpage > $stop_page ) {
            $continue = false;
          }
        }
      }
      else {
        $this->logger->logStatus("error: curl status: $cURL_status", get_class($this));
        $this->logger->logStatus($twitter_data, get_class($this));
        $continue = false;
      }
      return array($fcount, $mpage, $older_favs_smode, $stop_page, $new_favs_to_add, $last_fav_id,
         $last_page_fetched_favorites, $continue);
    } // end maintFavsFetch
    

    /**
     * archivingFavsFetch is used to support the favorites crawler's first 'archiving' stage, 
     * in which it sucks in all the user's favorites.  It starts with the 
     * largest page number (oldest favs), calculated based on the # of favs for the user as reported by twitter, 
     * and searches forward (newer)
     * until it reaches page 1 or runs out of API calls.  It may need to break up this stage over several runs 
     * due to API limits.
     * (This stage only happens once-- after this intitial archiving process,
     * the favs crawler switches into 'maintenance mode' as implemented by the method above,
     * and uses a limited # of API calls for each run.)
     */
    private function archivingFavsFetch ($fcount, $last_fav_id, $last_page_fetched_favorites, $continue) {

      $status_message = "";

      list($tweets, $cURL_status, $twitter_data) = $this->getFavsPage($last_page_fetched_favorites - 1);

      if ($cURL_status == 200) {
        try {
          if ($tweets == -1 || sizeof($tweets) == 0) {
            $this->logger->logStatus("in archivingFavsFetch; could not extract any tweets from response", get_class($this));
            throw new Exception("could not extract any tweets from response");
          }
          // $fcfg['oid'] = $this->owner_object->user_id;
          $pd = DAOFactory::getDAO('FavoritePostDAO');
          // $pd->setFavoriterId($this->owner_object->user_id);
          $status_message = "user id: " . $this->owner_object->user_id;
          $this->logger->logStatus($status_message, get_class($this));
          foreach ($tweets as $tweet) {
            $tweet['network'] = 'twitter';
            $this->logger->logStatus("working on fav: " . $tweet['post_id'], get_class($this));
            // $this->logger->logStatus(Utils::var_dump_ret($tweet), get_class($this));

            if ($pd->addFavorite($this->owner_object->user_id, $tweet) > 0) {
              $fcount++;
              // insert links contained in tweet
              $this->processTweetURLs($tweet);
            }
            else {
              $status_message = "have already stored fav ". $tweet['post_id'];
              $this->logger->logStatus($status_message, get_class($this));
            }

            // $this->logger->logStatus("current last fav id is:  $last_fav_id", get_class($this));
            if ($tweet['post_id'] > $last_fav_id) {
              $this->logger->logStatus("fav > $last_fav_id", get_class($this));
              $last_fav_id = $tweet['post_id'] + 0;
            }
          } // end foreach
        }
        catch(Exception $e) {
          $status_message = "In archivingFavsFetch, could not parse or process tweet XML for " . $this->instance->network_username;
          $this->logger->logStatus($status_message, get_class($this));
          $status_message = "";
          throw new Exception($status_message);
        }
        $last_page_fetched_favorites--;
        if ($last_page_fetched_favorites == 1) {
          $continue = false;
        }
      }
      else {
        $this->logger->logStatus("error: curl status: $cURL_status", get_class($this));
        $this->logger->logStatus($twitter_data, get_class($this));
        $continue = false;
      }
      return array($fcount, $last_fav_id, $last_page_fetched_favorites, $continue);
    } // end archivingFavsFetch
    

    /**
     * This helper method returns the parsed favorites from a given favorites page
     */
    private function getFavsPage($page) {

      $favs_call = str_replace("[id]", $this->owner_object->username, $this->api->cURL_source['favorites']);	
      $tweets = -1;
      $args = array();
      $args["page"] = $page;
      list($cURL_status, $twitter_data) = $this->api->apiRequest($favs_call, $args);
      if ($cURL_status == 200) {
        // Parse the XML file
        $tweets = $this->api->parseXML($twitter_data);
        if (!(isset($tweets) && sizeof($tweets) == 0) && $tweets == null) { // arghh, empty array evals to null.
          print "in getFavsPage- tweets: "; print_r($tweets);
          $this->logger->logStatus("in getFavsPage; could not extract any tweets from response", get_class($this));
          throw new Exception("could not extract any tweets from response");
        }
      }
      return array($tweets, $cURL_status, $twitter_data);
    }
    
    /**
     * cleanUpMissedFavsUnFavs is called by the twiter plugin. 
     * It pages back through the older pages of favs, checking for favs that are not yet in the database,
     * as well as favs that were added to the db but are no longer returned by twitter's API.  
     * However, that latter calculation, for un-fav'd tweets, is currently not reliable due to a bug on Twitter's end,
     * and so such tweets are not currently removed from the database.
     * Due to the same issue with the API, it's not clear whether all favs of older tweets are going to be actually
     * returned from twitter (that is, it is currently not returning some actually-favorited tweets in a given range). 
     * So, we may miss some older tweets that were in fact favorited, until Twitter fixes this.
     * The number of pages to page back for each run of the crawler is set by $THINKUP_CFG['tfavs_cleanup_pages'].
     * @author Amy Unruh
     */
   public function cleanUpMissedFavsUnFavs() {

     $this->logger->logStatus("*********in cleanUpMissedFavsUnFavs**********", get_class($this));
     $this->logger->logStatus("****owner user id: " . $this->owner_object->user_id . "\n", get_class($this));

     try {
       $fpd = DAOFactory::getDAO('FavoritePostDAO');

       $favs_cleanup_pages = 1; // default number of pages to process each time the crawler runs
       $pagesize = 20; // number of favs per page retrieved from the API call... (tbd: any way to get this from the API?)
       // get the number of older pages to check, from the config file. Used to calculate the default start 
       // page if that information is not yet set.
       $conf_older_favs_pages = (int)$this->config->getValue('tfavs_older_pages');
       if (is_integer($conf_older_favs_pages) && $conf_older_favs_pages > 0) {
         $default_start_page = $conf_older_favs_pages + 1;
       }
       else {
         $default_start_page = 2;
       }
       $last_page_of_favs = round($this->api->archive_limit / $pagesize) + 1;

       // get how many cleanup pages to process each time the crawler runs, from the config file. 
       // Fall back to default if not set.
       $conf_favs_cleanup_pages = (int)$this->config->getValue('tfavs_cleanup_pages');
       if (is_integer($conf_favs_cleanup_pages) && $conf_favs_cleanup_pages > 0) {
         $favs_cleanup_pages = $conf_favs_cleanup_pages;
       }

       $last_unfav_page_checked = $this->instance->last_unfav_page_checked;
       $start_page = $last_unfav_page_checked > 0? $last_unfav_page_checked + 1 : $default_start_page;
       $this->logger->logStatus("start page: $start_page, with $favs_cleanup_pages cleanup pages", get_class($this));
       $curr_favs_count = $this->owner_object->favorites_count;

       $count = 0; $page = $start_page;
       while ($count < $favs_cleanup_pages && $this->api->available && $this->api->available_api_calls_for_crawler ) {
         // get the favs from that page
         list($tweets, $cURL_status, $twitter_data) = $this->getFavsPage($page);
         if ($cURL_status != 200 || $tweets == -1) {
           // todo - handle more informatively
           $this->logger->logStatus("in cleanUpMissedFavsUnFavs, error with: $twitter_data", get_class($this));
           throw new Exception("in cleanUpUnFavs: error parsing favs"); 
         }
         if (sizeof($tweets) == 0) {
           // then done paging backwards through the favs.
           // reset pointer so that we start at the recent favs again next time through.
           $this->instance->last_unfav_page_checked = 0;
           break;
         }
         $min_tweet = $tweets[(sizeof($tweets) -1)]['post_id']; $max_tweet = $tweets[0]['post_id'];
         $this->logger->logStatus("in cleanUpUnFavs, page $page min and max: $min_tweet, $max_tweet", get_class($this));
         foreach ($tweets as $fav) {
           $fav['network'] = 'twitter';
           // check whether the tweet is in the db-- if not, add it.
           if ($fpd->addFavorite($this->owner_object->user_id, $fav) > 0) {
             // insert links contained in tweet
             $this->processTweetURLs($fav);
             $this->logger->logStatus("added fav " . $fav['post_id'], get_class($this));
           }
           else {
             $status_message = "have already stored fav ". $fav['post_id'];
             $this->logger->logStatus($status_message, get_class($this));
           }
         }
         // now for each favorited tweet in the database within the fetched range, check whether it's still favorited
         // This part of the method is currently disabled due to issues with the twitter API, which is not returning
         // all of the favorited tweets any more.  So, the fact that a previously-archived tweet is not returned, no longer indicates
         // that it was un-fav'd.
         // The method still IDs the 'missing' tweets, but no longer deletes them.  We may want to get rid of this check
         // altogether at some point.
         $fposts = $fpd->getAllFPostsUB($this->owner_object->user_id, 'twitter', $pagesize, $max_tweet + 1); 
         foreach ($fposts as $old_fav) {
           $old_fav_id = $old_fav->post_id;
           if ($old_fav < $min_tweet) {
             $this->logger->logStatus("old fav $old_fav_id out of range ", get_class($this));
             break; // all the rest will be out of range also then
           }
           // look for the old_fav_id in the array of fetched favs
           $found = false;
           foreach ($tweets as $tweet) {
             if ($old_fav_id == $tweet['post_id']) {
               // $this->logger->logStatus("tweet  still favorited:" . $old_fav_id, get_class($this));
               $found = true;
               break;
             }
           }
           if (!$found) { // if it's not there...
           // 14/10 arghh -- twitter is suddenly (temporarily?) not returning all fav'd tweets in a sequence.
           // skipping the delete for now, keep tabs on it.  Can check before delete with extra API request, but the point
           // of doing it this way was to avoid the additional API request...
           $this->logger->logStatus("twitter claims tweet not still favorited, but this is currently broken, so not deleting: " 
             . $old_fav_id, get_class($this));
           // 'unfavorite' by removing from favorites table
           // $fpd->unFavorite($old_fav_id, $this->owner_object->user_id);
         }
       }
       $this->instance->last_unfav_page_checked = $page++;
       if ($page > $last_page_of_favs) {
         $page = 0; 
         break;
       }
       $count++;
     }
   } // end try
   catch (Exception $e) {
     $this->logger->logStatus("$e", get_class($this));
     return false;
   }
   return true;
 } // end cleanUpUnFavs
    
    private function getSiteTweet($tid) {
      
      $tweet_deets = str_replace("[id]", $tid, $this->api->cURL_source['show_tweet']);
      list($cURL_status, $twitter_data) = $this->api->apiRequest($tweet_deets);

      if ($cURL_status == 200) {
          try {
              $tweets = $this->api->parseXML($twitter_data);
              foreach ($tweets as $tweet) { // there should only be one, right?
                // $this->logger->logStatus("got tweet info: " . Utils::var_dump_ret($tweet), get_class($this));
                return $tweet;
              }
          }
          catch(Exception $e) {
              $status_message = 'Could not parse tweet XML for $id';
              $this->logger->logStatus($status_message, get_class($this));
              return null;
          }
      } 
      elseif ($cURL_status == 404 || $cURL_status == 403) {
        $status_message = 'Could not parse tweet XML for $id';
        $this->logger->logStatus($status_message, get_class($this));
          try {
              $e = $this->api->parseError($twitter_data);
              $ped = DAOFactory::getDAO('PostErrorDAO');
              $ped->insertError($tid, 'twitter', $cURL_status, $e['error'], $this->owner_object->user_id);
              $status_message = 'Error saved to tweets.';
          }
          catch(Exception $e) {
              $status_message = 'Could not parse tweet XML for $tid';
          }
          return null;
      }
    }

}
