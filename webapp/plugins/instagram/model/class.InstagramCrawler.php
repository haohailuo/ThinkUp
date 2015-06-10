<?php
/**
 *
 * ThinkUp/webapp/plugins/instagram/model/PHP5.3/class.InstagramCrawler.php
 *
 * Copyright (c) 2013 Dimosthenis Nikoudis
 *
 * LICENSE:
 *
 * This file is part of ThinkUp (http://thinkup.com).
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
 *
 *
 * Instagram Crawler
 *
 * @author Dimosthenis Nikoudis <dnna[at]dnna[dot]gr>
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2013 Dimosthenis Nikoudis
 */
class InstagramCrawler {
    /**
     * @var Instance
     */
    var $instance;
    /**
     * @var Logger
     */
    var $logger;
    /**
     * @var int Maximum amount of time the crawler should spend fetching a profile or page in seconds
     */
    var $max_crawl_time;
    /**
     * @var InstagramAPIAccessor
     */
    var $api_accessor;
    /**
     * @param Instance $instance
     * @return InstagramCrawler
     */
    public function __construct($instance, $access_token, $max_crawl_time) {
        $this->instance = $instance;
        $this->logger = Logger::getInstance();
        $this->max_crawl_time = $max_crawl_time;
        $this->api_accessor = new InstagramAPIAccessor($access_token);
    }
    /**
     * If user doesn't exist in the datastore, fetch details from instagram API and insert into the datastore.
     * If $reload_from_instagram is true, update existing user details in store with data from instagram API.
     * @param int $user_id instagram user ID
     * @param str $found_in Where the user was found
     * @param str $username
     * @param str $full_name
     * @param str $avatar
     * @param bool $reload_from_instagram Defaults to false; if true will query instagram API and update existing user
     * @return User
     */
    public function fetchUser($user_id, $found_in, $username, $full_name, $avatar, $force_reload_from_instagram=false) {
        //assume all users except the instance user is a instagram profile, not a page
        $network = ($user_id == $this->instance->network_user_id)?$this->instance->network:'instagram';
        $user_dao = DAOFactory::getDAO('UserDAO');
        $user_object = null;
        if ($force_reload_from_instagram || !$user_dao->isUserInDB($user_id, $network)) {
            // Get user details and save them to DB
            $user_details = null;
            try {
                $user_details = $this->api_accessor->apiRequest('user', array('user_id'=> $user_id));
                $this->logger->logSuccess("Successfully fetched ".$user_id. " ".$network.
                    "'s details from Instagram", __METHOD__.','.__LINE__);
                $user_details->network = $network;
                $user = $this->parseUserDetails($user_details);
            } catch (Instagram\Core\ApiException $e) {
                if ($e->getMessage() == 'you cannot view this resource') {
                    $user = array();
                    $user["user_name"] = $username;
                    $user["full_name"] = $full_name;
                    $user["user_id"] = $user_id;
                    $user["avatar"] = $avatar;
                    $user['url'] = '';
                    $user["location"] = '';
                    $user["description"] = '';
                    $user["is_protected"] = 1;
                    $user["joined"] = ''; //Column 'joined' cannot be null
                    $user["network"] = 'instagram';
                    $this->logger->logInfo("Private user ".$username. " with limited details", __METHOD__.','.__LINE__);
                } else {
                    $this->logger->logInfo(get_class($e)." fetching ".$user_id." ". $network.
                        "'s details from Instagram API, error was ".$e->getMessage(), __METHOD__.','.__LINE__);
                }
            }

            if (isset($user)) {
                $user_object = new User($user, $found_in);
                $user_dao->updateUser($user_object);
            } else {
                $this->logger->logInfo("Error parsing user details ".Utils::varDumpToString($user_details),
                    __METHOD__.','.__LINE__);
            }
        }
        return $user_object;
    }

    /**
     * Get whether or not a user is private based on the relationship with current user. Only call this for users
     * who had a successful get user call.
     * @param  str $user_id
     * @return int 1 or 0
     */
    public function getIsUserPrivate($user_id) {
        $is_protected = 0;
        if ($user_id !== $this->instance->network_user_id) {
            $relationship = $this->api_accessor->apiRequest( 'relationship', array('user_id'=>$user_id) );
            if ($relationship->target_user_is_private) {
                $is_protected = 1;
            }
        }
        return $is_protected;
    }

    /**
     * Convert an Instagram user object into a ThinkUp user object.
     * @param array $details
     * @return array $user_vals
     */
    private function parseUserDetails(Instagram\User $details) {
        try {
            if ($details->getUserName() != null && $details->getId() != null) {
                $user_vals = array();

                $user_vals["post_count"] = $details->getMediaCount();
                $user_vals["follower_count"] = $details->getFollowersCount();
                $user_vals["user_name"] = $details->getUserName();
                $user_vals["full_name"] = $details->getFullName();
                $user_vals["user_id"] = $details->getId();
                $user_vals["avatar"] = $details->getProfilePicture();
                $user_vals['url'] = $details->getWebsite()!=null?$details->getWebsite():'';
                $user_vals["location"] = '';
                $user_vals["description"] = $details->getBio()!=null?$details->getBio():'';
                $user_vals["is_protected"] = 0;
                $user_vals["joined"] = ''; //Column 'joined' cannot be null
                $user_vals["network"] = $details->network;
                //this will help us in getting correct range of posts
                $user_vals["updated_time"] = isset($details->updated_time)?$details->updated_time:0;
                return $user_vals;
            }
        } catch (Instagram\Core\ApiException $e) {
            $this->logger->logInfo("Error fetching ".$details->username.
            "'s details. Instagram says '".$e->getMessage()."'", __METHOD__.','.__LINE__);
        }
    }
    /**
     * Fetch and save the posts and replies for the crawler's instance. This function will loop back through the
     * user's or pages archive of posts.
     * // If archive isn't loaded, attempt to load it
     * api_call = /users/{id}/media/recent
     *
     * If instances.is_archive_loaded == 0 and instances.last_post_id != ''
     * set api_call param max_id = last_post_id.
     *
     *     posts = Fetch page of media via api_call
     *
     *     insert posts
     * endif
     *
     * //Get recent
     * api_call = /users/{id}/media/recent
     * param min_timestamp = instance.last_crawled_time (-week if not set)
     * posts = Fetch page of media via api_call
     *
     * insert posts
     *
     */
    public function fetchPostsAndReplies() {
        //Cap crawl time for very busy pages with thousands of likes/comments
        $fetch_stop_time = time() + $this->max_crawl_time;

        $id = $this->instance->network_user_id;
        $network = $this->instance->network;

        //Force-refresh instance user in data store
        self::fetchUser($this->instance->network_user_id, 'Owner info', $this->instance->network_username,
            null, null, true);

        // If archive isn't loaded, attempt to load it
        if (!$this->instance->is_archive_loaded_posts && $this->instance->last_post_id != '') {
            $this->logger->logUserInfo("Media archive is not loaded, fetching starting from max_id "
                .$this->instance->last_post_id, __METHOD__.','.__LINE__);
            $api_param = array('max_id' => $this->instance->last_post_id);
            $posts = $this->api_accessor->apiRequest('media', $api_param);

            $fetch_next_page = true;
            while ($fetch_next_page) {
                if ($posts->count() > 0) {
                    $this->logger->logInfo($posts->count()." Instagram posts found", __METHOD__.','.__LINE__);

                    $this->processPosts($posts, $network);

                    if ($posts->getNext() != null) {
                        $api_param['max_id'] = $posts->getNext();
                        $posts = $this->api_accessor->apiRequest('media', $api_param);
                    } else {
                        $fetch_next_page = false;
                        $this->instance->is_archive_loaded_posts = true;
                    }
                } else {
                    $this->logger->logInfo("No Instagram posts found before max id ".$this->instance->last_post_id,
                        __METHOD__.','.__LINE__);
                    $fetch_next_page = false;
                }
            }
            if (time() > $fetch_stop_time) {
                $fetch_next_page = false;
                $this->logger->logUserInfo("Stopping this service user's crawl because it has exceeded max time of ".
                ($this->max_crawl_time/60)." minute(s). ",__METHOD__.','.__LINE__);
            }
        }

        if ($this->instance->is_archive_loaded_posts) {
            $this->logger->logUserInfo("Media archive is loaded",__METHOD__.','.__LINE__);
        }

        //Get recent media
        $fetch_next_page = true;
        $api_param = array('count' => 20);

        $this->logger->logUserInfo("About to request recent media",__METHOD__.','.__LINE__);
        $posts = $this->api_accessor->apiRequest('media', $api_param);
        $this->logger->logUserInfo("Recent media requested with params ".Utils::varDumpToString($api_param),
            __METHOD__.','.__LINE__);

        while ($fetch_next_page) {
            if ($posts->count() > 0) {
                $this->logger->logInfo($posts->count()." Instagram posts found", __METHOD__.','.__LINE__);

                $did_capture_new_data = $this->processPosts($posts, $network);

                if ($did_capture_new_data && $posts->getNext() != null) {
                    $api_param['max_id'] = $posts->getNext();
                    $posts = $this->api_accessor->apiRequest('media', $api_param);
                } else {
                    if (!$did_capture_new_data) {
                        $this->logger->logInfo("No new data captured in last set, stopping here",
                            __METHOD__.','.__LINE__);
                    } else {
                        $this->logger->logInfo("No posts in the next set", __METHOD__.','.__LINE__);
                    }
                    $fetch_next_page = false;
                }
            } else {
                $this->logger->logInfo("No Instagram posts found for ID $id", __METHOD__.','.__LINE__);
                $fetch_next_page = false;
            }
            if (time() > $fetch_stop_time) {
                $fetch_next_page = false;
                $this->logger->logUserInfo("Stopping this service user's crawl because it has exceeded max time of ".
                ($this->max_crawl_time/60)." minute(s). ",__METHOD__.','.__LINE__);
            }
        }
    }
    /**
     * Fetch and save the instance users's followers.
     */
    public function fetchFollowers() {
        $plugin_dao = DAOFactory::getDAO('PluginDAO');
        $plugin_id = $plugin_dao->getPluginId('instagram');
        $namespace = OptionDAO::PLUGIN_OPTIONS.'-'.$plugin_id;
        $id = $this->instance->network_user_id;
        $option_dao = DAOFactory::getDAO('OptionDAO');
        $network = $this->instance->network;

        //Cap crawl time for very busy pages with thousands of likes/comments
        $fetch_stop_time = time() + $this->max_crawl_time;

        //Checks if last followers update was over 2 days ago and run storeFollowers if it is.
        $followers_last_updated = $option_dao->getOptionByName($namespace,'last_crawled_followers');
        $followers_last_updated_check = microtime(true) - 172800;
        if ($followers_last_updated == NULL) {
            $this->storeFollowers();
            $option_dao->insertOption($namespace,'last_crawled_followers', microtime(true));
        } elseif ($followers_last_updated->option_value < $followers_last_updated_check) {
            $this->storeFollowers();
            $option_dao->updateOptionByName($namespace,'last_crawled_followers', microtime(true));
        }

        if (time() > $fetch_stop_time) {
            $fetch_next_page = false;
            $this->logger->logUserInfo("Stopping this service user's crawl because it has exceeded max time of ".
            ($this->max_crawl_time/60)." minute(s). ",__METHOD__.','.__LINE__);
        }
    }
    /**
     * Convert a collection of profile posts into ThinkUp posts and users
     * @param Object $posts
     * @param str $source The network for the post, always 'instagram'
     * @return Whether or not a post (or comment), user or like was written to storage
     */
    private function processPosts(Instagram\Collection\MediaCollection $posts, $network) {
        $thinkup_posts = array();
        $total_added_posts = 0;

        $thinkup_users = array();
        $total_added_users = 0;

        $thinkup_likes = array();
        $total_added_likes = 0;

        //efficiency control vars
        $must_process_likes = true;
        $must_process_comments = true;
        $post_comments_added = 0;
        $post_likes_added = 0;
        $comments_difference = false;
        $likes_difference = false;

        $post_dao = DAOFactory::getDAO('PostDAO');

        foreach ($posts as $index=>$p) {
            $post_id = $p->getId();
            $this->logger->logInfo("Beginning to process ".$post_id.", post ".($index+1)." of ".($posts->count()),
                __METHOD__.','.__LINE__);

            // stream can contain posts from multiple users.  get profile for this post
            $profile = $p->getUser();
            $is_protected = 0;
            //Get likes count
            $likes_count = $p->getLikesCount();
            $comments = $p->getComments();

            $post_in_storage = $post_dao->getPost($post_id, $network);

            //Figure out if we have to process likes and comments
            if (isset($post_in_storage)) {
                $this->logger->logInfo("Post ".$post_id. " already in storage", __METHOD__.','.__LINE__);
                if ($post_in_storage->favlike_count_cache >= $likes_count ) {
                    $must_process_likes = false;
                    $this->logger->logInfo("Already have ".$likes_count." like(s) for post ".$post_id.
                    "in storage; skipping like processing", __METHOD__.','.__LINE__);
                } else  {
                    $likes_difference = $likes_count - $post_in_storage->favlike_count_cache;
                    $this->logger->logInfo($likes_difference." new like(s) to process for post ".$post_id,
                    __METHOD__.','.__LINE__);
                }

                $commentsCount = $comments->count();
                if ($commentsCount > 0) {
                    if ($post_in_storage->reply_count_cache >= $commentsCount) {
                        $must_process_comments = false;
                        $this->logger->logInfo("Already have ".$commentsCount." comment(s) for post ".$post_id.
                        "; skipping comment processing", __METHOD__.','.__LINE__);
                    } else {
                        $comments_difference = $commentsCount - $post_in_storage->reply_count_cache;
                        $this->logger->logInfo($comments_difference." new comment(s) of ".$commentsCount.
                        " total to process for post ".$post_id, __METHOD__.','.__LINE__);
                    }
                }
            } else {
                $this->logger->logInfo("Post ".$post_id. " not in storage", __METHOD__.','.__LINE__);
            }
            // If we dont already have this photo
            if (!isset($post_in_storage)) {
                // Photos may be posted without a caption
                // Note that if you post a photo without a caption and then reply to it with the first comment instagram
                // treats this as your caption.
                if(strlen($p->getCaption()) >0 ) {
                    $text = $p->getCaption();
                } else {
                    $text = "";
                }

                $photo_to_process = array(
                  // Post details
                  "post_id"=>$post_id,
                  "author_username"=>$profile->getUserName(),
                  "author_fullname"=>$profile->getFullName(),
                  "author_avatar"=>$profile->getProfilePicture(),
                  "author_user_id"=>$profile->getId(),
                  "post_text"=> $text,
                  "pub_date"=>DateTime::createFromFormat('U', $p->getCreatedTime())->format('Y-m-d H:i'),
                  "favlike_count_cache"=>$likes_count,
                  "in_reply_to_user_id"=>'', // assume only one recipient
                  "in_reply_to_post_id"=>'',
                  "source"=>'',
                  'network'=>$network,
                  'is_protected'=>$is_protected,
                  'location'=>'',
                  // Photo details
                  'permalink'=>$p->getLink(),
                  'standard_resolution_url'=>$p->getStandardRes()->url,
                  'low_resolution_url'=>$p->getLowRes()->url,
                  'thumbnail_url'=>$p->getThumbnail()->url,
                  'filter'=>$p->getFilter(),
                  'is_short_video' => (($p->getType() == 'video')?1:0)
                );

                $new_photo_key = $this->storePhotoAndAuthor($photo_to_process, "Owner stream");

                if ($new_photo_key !== false ) {
                    $total_added_posts++;
                    $this->instance->last_post_id = $post_id;
                }
            } else { // post already exists in storage
                if ($must_process_likes) { //update its like count only
                    $post_dao->updateFavLikeCount($post_id, $network, $likes_count);
                    $this->logger->logInfo("Updated Like count for post ".$post_id . " to ". $likes_count,
                    __METHOD__.','.__LINE__);
                }
            }

            if ($must_process_comments) {
                if ($comments->count() > 0) {
                    $comments_captured = 0;
                    $post_comments = $comments;
                    if ($post_comments->count() > 0) {
                        foreach ($post_comments as $c) {
                            $comment_id = $c->getId();
                            //only add to queue if not already in storage
                            $comment_in_storage = $post_dao->getPost($comment_id, $network);
                            if (!isset($comment_in_storage)) {
                                $comment_author = $c->getUser();
                                $comment_to_process = array(
                                    "post_id"=>$comment_id,
                                    "author_username"=>$comment_author->getUserName(),
                                    "author_fullname"=>$comment_author->getFullName(),
                                    "author_avatar"=>$comment_author->getProfilePicture(),
                                    "author_user_id"=>$comment_author->getId(),
                                    "post_text"=>$c->getText(),
                                    "pub_date"=>DateTime::createFromFormat('U',
                                     $p->getCreatedTime())->format('Y-m-d H:i'),
                                    "in_reply_to_user_id"=>$profile->getId(),
                                    "in_reply_to_post_id"=>$post_id,
                                    "source"=>'', 'network'=>$network,
                                    'is_protected'=>$is_protected,
                                    'location'=>''
                                );
                                array_push($thinkup_posts, $comment_to_process);
                                $comments_captured = $comments_captured + 1;
                            }
                        }
                    }
                    $post_comments_added = $post_comments_added +
                    $this->storePostsAndAuthors($thinkup_posts, "Post stream comments");

                    //free up memory
                    $thinkup_posts = array();

                    if (is_int($comments_difference) && $post_comments_added >= $comments_difference) {
                        $must_process_comments = false;
                        $this->logger->logInfo("Caught up on post ".$post_id."'s balance of ".
                        $comments_difference." comments; stopping comment processing", __METHOD__.','.__LINE__);
                    }
                }
                if ($post_comments_added > 0) { //let user know
                    $this->logger->logUserSuccess("Added ".$post_comments_added." comment(s) for post ". $post_id,
                    __METHOD__.','.__LINE__);
                } else {
                    $this->logger->logInfo("Added ".$post_comments_added." comment(s) for post ". $post_id,
                    __METHOD__.','.__LINE__);
                }
                $total_added_posts = $total_added_posts + $post_comments_added;
            }

            //process "likes"
            if ($must_process_likes) {
                if ($likes_count > 0) {
                    $likes_captured = 0;
                    $post_likes = $p->getLikes();
                    $post_likes_count = $likes_count;
                    if ($post_likes_count > 0) {
                        foreach ($post_likes as $l) {
                            //Get users
                            $user_to_add = array(
                                "user_name"=>$l->getUserName(),
                                "full_name"=>$l->getFullName(),
                                "user_id"=>$l->getId(),
                                "avatar"=>$l->getProfilePicture(),
                                "description"=>'',
                                "url"=>'',
                                "is_protected"=>0,
                                "follower_count"=>0,
                                "post_count"=>0,
                                "joined"=>'',
                                "found_in"=>"Likes",
                                "network"=>'instagram'
                            ); //Users are always set to network=instagram
                            array_push($thinkup_users, $user_to_add);

                            $fav_to_add = array(
                                "favoriter_id"=>$l->getId(),
                                "network"=>$network,
                                "author_user_id"=>$profile->getId(),
                                "post_id"=>$post_id
                            );
                            array_push($thinkup_likes, $fav_to_add);
                            $likes_captured = $likes_captured + 1;
                        }
                    }

                    $total_added_users = $total_added_users + $this->calculateNumberOfUsersStored($thinkup_users,
                    "Likes");
                    $post_likes_added = $post_likes_added + $this->storeLikes($thinkup_likes);

                    //free up memory
                    $thinkup_users = array();
                    $thinkup_likes = array();

                    if (is_int($likes_difference) && $post_likes_added >= $likes_difference) {
                        $must_process_likes = false;
                        $this->logger->logInfo("Caught up on post ".$post_id."'s balance of ".
                        $likes_difference." likes; stopping like processing", __METHOD__.','.__LINE__);
                    }
                }
                $this->logger->logInfo("Added ".$post_likes_added." like(s) for post ".$post_id,
                __METHOD__.','.__LINE__);
                $total_added_likes = $total_added_likes + $post_likes_added;
            }
            //free up memory
            $thinkup_users = array();
            $thinkup_likes = array();
            //reset control vars for next post
            $must_process_likes = true;
            $must_process_comments = true;
            $post_comments_added = 0;
            $post_likes_added = 0;
            $comments_difference = false;
            $likes_difference = false;
        }

        $this->logger->logUserSuccess("On this set, captured ".$total_added_posts." post(s), ".
        $total_added_users." user(s) and ".$total_added_likes." like(s)", __METHOD__.','.__LINE__);
        //Return whether or not something new was written to storage
        if ($total_added_posts == 0 && $total_added_users == 0 && $total_added_likes == 0) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Store an array of posts made on instagram in the database
     * @param  arr $posts           An array of posts to store
     * @param  str $posts_source Where the posts came from e.g. instagram
     * @return int number of posts added
     */
    private function storePostsAndAuthors($posts, $posts_source){
        $total_added_posts = 0;
        $added_post = 0;
        foreach ($posts as $post) {
            $added_post_key = $this->storePostAndAuthor($post, $posts_source);
            if ($added_post !== false) {
                $this->logger->logInfo("Added post ID ".$post["post_id"]." on ".$post["network"].
                " for ".$post["author_username"].":".substr($post["post_text"],0, 20)."...", __METHOD__.','.__LINE__);
                $total_added_posts = $total_added_posts ++;
            } else  {
                $this->logger->logInfo("Didn't add post ".$post["post_id"]." on ".$post["network"].
                " for ".$post["author_username"].":".substr($post["post_text"],0, 20)."...", __METHOD__.','.__LINE__);
            }
            $added_post = 0;
        }
        return $total_added_posts;
    }
    /**
     * Store a post in the posts table and add the author to the users table
     * @param  arr $post  An array of posts and associated users
     * @param  str $post_source Where the posts were made e.g. instagram
     * @return int id of the row the post
     */
    private function storePostAndAuthor($post, $post_source){
        $post_dao = DAOFactory::getDAO('PostDAO');
        if (isset($post['author_user_id'])) {
            try {
                $user_object = $this->fetchUser($post['author_user_id'], $post_source, $post['author_username'],
                    $post['author_fullname'], $post['author_avatar'], false);
                if (isset($user_object)) {
                    $post["author_username"] = $user_object->username;
                    $post["author_fullname"] = $user_object->full_name;
                    $post["author_avatar"] = $user_object->avatar;
                    $post["location"] = $user_object->location;
                }
            } catch (Instagram\Core\ApiException $e) {
                $this->logger->logInfo(get_class($e). " Error fetching ".$post['author_user_id'].
                "'s details from Instagram API, error was ".$e->getMessage(), __METHOD__.','.__LINE__);
            }
        }
        $added_post_key = $post_dao->addPost($post);
        return $added_post_key;
    }
    /**
     * Store a photo into the photos table and add the author to the users table
     * @param  arr $photo        An array of photos to add to the database
     * @param  str $photo_source Where the post came from e.g. instagram
     * @return int id of the row of the photo
     */
    private function storePhotoAndAuthor($photo, $photo_source){
        $photo_dao = DAOFactory::getDAO('PhotoDAO');
        if (isset($photo['author_user_id'])) {
            $user_object = $this->fetchUser($photo['author_user_id'], $photo_source, $photo['author_username'],
                $photo['author_fullname'], $photo['author_avatar'], false);
            if (isset($user_object)) {
                $photo["author_username"] = $user_object->username;
                $photo["author_fullname"] = $user_object->full_name;
                $photo["author_avatar"] = $user_object->avatar;
                $photo["location"] = $user_object->location;
            }
        }
        $added_photo_key = $photo_dao->addPhoto($photo);
        return $added_photo_key;
    }
    /**
     * Counts how many users were added to the database
     * @param  arr $users An array of users for which to check if they were stored
     * @param  str $users_source Where the users were found
     * @return int Number of users from the input array that are in the users table
     */
    private function calculateNumberOfUsersStored($users, $users_source) {
        $added_users = 0;
        if (count($users) > 0) {
            foreach ($users as $user) {
                $user_object = $this->fetchUser($user['user_id'], $users_source, $user['user_name'],
                    $user['full_name'], $user['avatar']);
                if (isset($user_object)) {
                    $added_users = $added_users + 1;
                }
            }
        }
        return $added_users;
    }
    /**
     * Stores information about users who have liked posts in the favorites table
     * @param  arr $likes An array of posts which have been liked and details about the liker
     * @return int number of likes which were added
     */
    private function storeLikes($likes) {
        $added_likes = 0;
        if (count($likes) > 0) {
            $fav_dao = DAOFactory::getDAO('FavoritePostDAO');
            foreach ($likes as $like) {
                $added_likes = $added_likes + $fav_dao->addFavorite($like['favoriter_id'], $like);
            }
        }
        return $added_likes;
    }
    /**
     * Retrives all of a users followers from the Instagram API and stores them in the database
     * @return null
     */
    private function storeFollowers() {
        if ($this->instance->network != 'instagram') {
            return;
        }
        //Retrieve followers via the Instagram API
        $user_id = $this->instance->network_user_id;
        $access_token = $this->access_token;
        $network = ($user_id == $this->instance->network_user_id)?$this->instance->network:'instagram';
        try {
            $followers = $this->api_accessor->apiRequest('followers');
        } catch (Instagram\Core\ApiException $e) {
            $this->logger->logInfo(get_class($e). " Error fetching followers from Instagram API, error was ".
            $e->getMessage(), __METHOD__.','.__LINE__);
            return;
        }

        if (isset($followers)) {
            //store relationships in follows table
            $follows_dao = DAOFactory::getDAO('FollowDAO');
            $count_dao = DAOFactory::getDAO('CountHistoryDAO');
            $user_dao = DAOFactory::getDAO('UserDAO');

            foreach ($followers as $follower) {
                $follower_id = null;
                try {
                    $follower_id = $follower->getId();
                } catch (Instagram\Core\ApiException $e) {
                    $this->logger->logInfo(get_class($e). " Error fetching ".Utils::varDumpToString($follower).
                    "'s details from Instagram API, error was ".$e->getMessage(), __METHOD__.','.__LINE__);
                }
                if (isset($follower_id)) {
                    if ($follows_dao->followExists($user_id, $follower_id, $network)) {
                        // follow relationship already exists
                        $follows_dao->update($user_id, $follower_id, $network);
                    } else {
                        // follow relationship does not exist yet
                        $follows_dao->insert($user_id, $follower_id, $network);
                    }

                    $follower_details = $follower;
                    if (isset($follower_details)) {
                        $follower_details->network = $network;
                    }

                    $follower = $this->parseUserDetails($follower_details);
                    $follower_object = new User($follower);
                    if (isset($follower_object)) {
                        $user_dao->updateUser($follower_object);
                    }
                }
            }
            //totals in follower_count table
            $count_dao->insert($user_id, $network, $followers->count(), null, 'followers');
        } else {
            throw new Instagram\Core\ApiAuthException('Error retrieving friends');
        }
        $this->logger->logInfo("Ending", __METHOD__.','.__LINE__);
    }
}
