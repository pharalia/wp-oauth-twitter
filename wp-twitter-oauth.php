<?php
/*
Plugin Name: OAuth Twitter Widget
Plugin URI: https://github.com/pharalia/wp-oauth-twitter
Description: Twitter Widget for WordPress supporting the v1.1 API, based upon the Wickett Twitter Widget and the twitteroauth PHP library
Version: 1.0
Author: Michael Oldroyd
Author URI: http://www.michaeloldroyd.co.uk/
License: GPLv2
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

add_action('init','mo_register_twitter');

function mo_register_twitter() {
	wp_register_script('twitter-intents','https://platform.twitter.com/widgets.js');
	wp_register_style('twitter-styles',plugin_dir_url(__FILE__) . 'styles.css');
	wp_enqueue_script('twitter-intents');
	wp_enqueue_style('twitter-styles');
}

/* if ( ! class_exists( 'Automattic_Deprecated_Notice' ) ) {
	require_once( '_inc/class.automattic-deprecated-notice.php' );
	$adn = new Automattic_Deprecated_Notice;
} */

// inits json decoder/encoder object if not already available
global $wp_version;
if ( version_compare( $wp_version, '2.9', '<' ) && !class_exists( 'Services_JSON' ) ) {
	include_once( dirname( __FILE__ ) . '/class.json.php' );
}

if ( !function_exists('wpcom_time_since') ) :
/*
 * Time since function taken from WordPress.com
 */

function wpcom_time_since( $original, $do_more = 0 ) {
        // array of time period chunks
        $chunks = array(
                array(60 * 60 * 24 * 365 , 'year'),
                array(60 * 60 * 24 * 30 , 'month'),
                array(60 * 60 * 24 * 7, 'week'),
                array(60 * 60 * 24 , 'day'),
                array(60 * 60 , 'hour'),
                array(60 , 'minute'),
        );

        $today = time();
        $since = $today - $original;

        for ($i = 0, $j = count($chunks); $i < $j; $i++) {
                $seconds = $chunks[$i][0];
                $name = $chunks[$i][1];

                if (($count = floor($since / $seconds)) != 0)
                        break;
        }

        $print = ($count == 1) ? '1 '.$name : "$count {$name}s";

        if ($i + 1 < $j) {
                $seconds2 = $chunks[$i + 1][0];
                $name2 = $chunks[$i + 1][1];

                // add second item if it's greater than 0
                if ( (($count2 = floor(($since - ($seconds * $count)) / $seconds2)) != 0) && $do_more )
                        $print .= ($count2 == 1) ? ', 1 '.$name2 : ", $count2 {$name2}s";
        }
        return $print;
}
endif;

if ( !function_exists('http_build_query') ) :
    function http_build_query( $query_data, $numeric_prefix='', $arg_separator='&' ) {
       $arr = array();
       foreach ( $query_data as $key => $val )
         $arr[] = urlencode($numeric_prefix.$key) . '=' . urlencode($val);
       return implode($arr, $arg_separator);
    }
endif;

class MO_Twitter_Oauth_Widget extends WP_Widget {

	function MO_Twitter_Oauth_Widget() {
		$widget_ops = array('classname' => 'widget_twitter', 'description' => __( 'Display your tweets from Twitter') );
		parent::WP_Widget('mo_twitter', __('Twitter'), $widget_ops);
	}

	function widget( $args, $instance ) {
		
		require_once('twitteroauth.php');
		
		extract( $args );
		
		$account = trim( urlencode( $instance['account'] ) );
		if ( empty($account) ) return;
		$title = apply_filters('widget_title', $instance['title']);
		//if ( empty($title) ) $title = __( 'Twitter Updates' );
		$show = absint( $instance['show'] );  // # of Updates to show
		if ( $show > 200 ) // Twitter paginates at 200 max tweets. update() should not have accepted greater than 20
			$show = 200;
		$hidereplies = (bool) $instance['hidereplies'];
		$include_retweets = (bool) $instance['includeretweets'];
		
		$params = array(
			'screen_name'=>$account, // Twitter account name
			'trim_user'=>true, // only basic user data (slims the result)
			'include_entities'=>false // as of Sept 2010 entities were not included in all applicable Tweets. regex still better
		);
		$title = trim($title);
		if (!empty($title)) {
			echo "{$before_widget}{$before_title}<a href='" . esc_url( "http://twitter.com/{$account}" ) . "'>" . esc_html($title) . "</a>{$after_title}";
		} else {
			echo "{$before_widget}";
		}
		
		if (!$tweets = wp_cache_get( 'widget-twitter-' . $this->number , 'widget' ) ) {

			/**
			 * The exclude_replies parameter filters out replies on the server. If combined with count it only filters that number of tweets (not all tweets up to the requested count)
			 * If we are not filtering out replies then we should specify our requested tweet count
			 */
			if ( $hidereplies )
				$params['exclude_replies'] = true;
			else
				$params['count'] = $show;
			if ( $include_retweets )
				$params['include_rts'] = true;

			$twitter_json_url = esc_url_raw( 'https://api.twitter.com/1.1/statuses/user_timeline.json' , array('http', 'https') );
			
			unset($params);
			
			$connection  = new TwitterOAuth(
				$instance['consumer_key'], 
				$instance['consumer_secret'], 
				$instance['access_key'], 
				$instance['access_secret']
			);
			
			$response    = $connection->get("statuses/user_timeline");
			
			$response_code = $connection->http_code;
			
			if ( 200 == $response_code ) {
				$tweets = $response;
				$expire = 900;
				if ( !is_array( $tweets ) || isset( $tweets['error'] ) ) {
					$tweets = 'error';
					$expire = 300;
				}
			} else {
				$tweets = 'error';
				$expire = 300;
				wp_cache_add( 'widget-twitter-response-code-' . $this->number, $response_code, 'widget', $expire);
			}

			wp_cache_add( 'widget-twitter-' . $this->number, $tweets, 'widget', $expire );
		}

		if ( 'error' != $tweets ) :
			$before_timesince = ' ';
			if ( isset( $instance['beforetimesince'] ) && !empty( $instance['beforetimesince'] ) )
				$before_timesince = esc_html($instance['beforetimesince']);
			$before_tweet = '';
			if ( isset( $instance['beforetweet'] ) && !empty( $instance['beforetweet'] ) )
				$before_tweet = stripslashes(wp_filter_post_kses($instance['beforetweet']));

			echo '<ul class="tweets">' . "\n";

			$tweets_out = 0;

			foreach ( (array) $tweets as $tweet ) {
				if ( $tweets_out >= $show )
					break;

				if ( empty( $tweet->text ) )
					continue;

				$text = make_clickable( esc_html( $tweet->text ) );

				/*
				 * Create links from plain text based on Twitter patterns
				 * @link http://github.com/mzsanford/twitter-text-rb/blob/master/lib/regex.rb Official Twitter regex
				 */
				$text = preg_replace_callback('/(^|[^0-9A-Z&\/]+)(#|\xef\xbc\x83)([0-9A-Z_]*[A-Z_]+[a-z0-9_\xc0-\xd6\xd8-\xf6\xf8\xff]*)/iu',  array($this, '_wpcom_widget_twitter_hashtag'), $text);
				$text = preg_replace_callback('/([^a-zA-Z0-9_]|^)([@\xef\xbc\xa0]+)([a-zA-Z0-9_]{1,20})(\/[a-zA-Z][a-zA-Z0-9\x80-\xff-]{0,79})?/u', array($this, '_wpcom_widget_twitter_username'), $text);
				if ( isset($tweet->id_str) ) {
					$tweet_id = urlencode($tweet->id_str);
				} else {
					$tweet_id = urlencode($tweet->id);
				}
				//var_dump($tweet);
				echo 
					sprintf(
						"<li class='twitter-tweet-container clearfix'>
							<a title='View %2\$s&#39;s Profile' target='_blank' href='https://twitter.com/intent/user?screen_name=%3\$s' class='twitter-profile-container clearfix'>
								<img class='twitter-profile-image' src='%s' alt='' />
								<span class='twitter-profile-name'>%s</span>
								<span class='twitter-profile-username'>@%s</span>
							</a>
							<div class='twitter-tweet-content'>
							%s%s%s
							</div>
							<div class='twitter-intents-container clearfix'>
								<a title='Reply to this Tweet' class='twitter-intent-reply' href='https://twitter.com/intent/tweet?in_reply_to=%8\$d'><span class='icon'></span><span class='label'>Reply</span></a><a 
								title='Retweet this Tweet' class='twitter-intent-retweet' href='https://twitter.com/intent/retweet?tweet_id=%8\$d'><span class='icon'></span><span class='label'>Retweet</span></a><a 
								title='Favourite this Tweet' class='twitter-intent-favourite' href='https://twitter.com/intent/favorite?tweet_id=%8\$d'><span class='icon'></span><span class='label'>Favourite</span></a>
							</div>
							<a target='_blank' class='twitter-tweet-timestamp' href='https://twitter.com/%s/statuses/%s' class='timesince'>%s</a>
						</li>\n",
						$tweet->user->profile_image_url_https,
						$tweet->user->name,
						"{$tweet->user->screen_name}",
						$before_tweet,
						$text,
						$before_timesince,
						$account,
						$tweet_id,
						str_replace(' ', '&nbsp;', date('j\<\s\u\p\>S\<\/\s\u\p\> M y - H:i',strtotime($tweet->created_at)))
					);
				unset($tweet_id);
				$tweets_out++;
			}

			echo "</ul>\n";
		else :
			if ( 401 == wp_cache_get( 'widget-twitter-response-code-' . $this->number , 'widget' ) )
				echo '<p>' . sprintf( __( 'Error: Please make sure the Twitter account is <a href="%s">public</a>.'), 'http://support.twitter.com/forums/10711/entries/14016' ) . '</p>';
			else
				echo '<p>' . esc_html__('Error: Twitter did not respond. Please wait a few minutes and refresh this page. ' . wp_cache_get( 'widget-twitter-response-code-' . $this->number , 'widget' )) . '</p>';
		endif;

		echo $after_widget;
	}

	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		$instance['account'] = trim( strip_tags( stripslashes( $new_instance['account'] ) ) );
		$instance['account'] = str_replace('http://twitter.com/', '', $instance['account']);
		$instance['account'] = str_replace('/', '', $instance['account']);
		$instance['account'] = str_replace('@', '', $instance['account']);
		$instance['account'] = str_replace('#!', '', $instance['account']); // account for the Ajax URI
		$instance['title'] = strip_tags(stripslashes($new_instance['title']));
		$instance['show'] = absint($new_instance['show']);
		$instance['hidereplies'] = isset($new_instance['hidereplies']);
		$instance['includeretweets'] = isset($new_instance['includeretweets']);
		$instance['beforetimesince'] = $new_instance['beforetimesince'];
		$instance['consumer_key'] = $new_instance['consumer_key'];
		$instance['consumer_secret'] = $new_instance['consumer_secret'];
		$instance['access_key'] = $new_instance['access_key'];
		$instance['access_secret'] = $new_instance['access_secret'];

		wp_cache_delete( 'widget-twitter-' . $this->number , 'widget' );
		wp_cache_delete( 'widget-twitter-response-code-' . $this->number, 'widget' );

		return $instance;
	}

	function form( $instance ) {
		//Defaults
		$instance = wp_parse_args( (array) $instance, array('account' => '', 'title' => '', 'show' => 5, 'hidereplies' => false) );

		$account = esc_attr($instance['account']);
		$title = esc_attr($instance['title']);
		$show = absint($instance['show']);
		$consumer_key = esc_attr($instance['consumer_key']);
		$consumer_secret = esc_attr($instance['consumer_secret']);
		$access_key = esc_attr($instance['access_key']);
		$access_secret = esc_attr($instance['access_secret']);
		if ( $show < 1 || 20 < $show )
			$show = 5;
		$hidereplies = (bool) $instance['hidereplies'];
		$include_retweets = (bool) $instance['includeretweets'];
		$before_timesince = esc_attr($instance['beforetimesince']);

		echo '<p><label for="' . $this->get_field_id('title') . '">' . esc_html__('Title:') . '
		<input class="widefat" id="' . $this->get_field_id('title') . '" name="' . $this->get_field_name('title') . '" type="text" value="' . $title . '" />
		</label></p><hr />';
		# Consumer Key
		echo '<p><label for="' . $this->get_field_id('consumer_key') . '">' . esc_html__('Consumer Key:') . '
		<input class="widefat" id="' . $this->get_field_id('consumer_key') . '" name="' . $this->get_field_name('consumer_key') . '" type="text" value="' . $consumer_key . '" />
		</label></p>';
		# Consumer Secret
		echo '<p><label for="' . $this->get_field_id('consumer_secret') . '">' . esc_html__('Consumer Secret:') . '
		<input class="widefat" id="' . $this->get_field_id('consumer_secret') . '" name="' . $this->get_field_name('consumer_secret') . '" type="text" value="' . $consumer_secret . '" />
		</label></p><hr />';
		# Access Token
		echo '<p><label for="' . $this->get_field_id('access_key') . '">' . esc_html__('Access Key:') . '
		<input class="widefat" id="' . $this->get_field_id('access_key') . '" name="' . $this->get_field_name('access_key') . '" type="text" value="' . $access_key . '" />
		</label></p>';
		# Access Secret
		echo '<p><label for="' . $this->get_field_id('access_secret') . '">' . esc_html__('Access Secret:') . '
		<input class="widefat" id="' . $this->get_field_id('access_secret') . '" name="' . $this->get_field_name('access_secret') . '" type="text" value="' . $access_secret . '" />
		</label></p>';
		# Continued...
		echo '<p><label for="' . $this->get_field_id('account') . '">' . esc_html__('Twitter username:') . ' <a href="http://support.wordpress.com/widgets/twitter-widget/#twitter-username" target="_blank">( ? )</a>
		<input class="widefat" id="' . $this->get_field_id('account') . '" name="' . $this->get_field_name('account') . '" type="text" value="' . $account . '" />
		</label></p>
		<p><label for="' . $this->get_field_id('show') . '">' . esc_html__('Maximum number of tweets to show:') . '
			<select id="' . $this->get_field_id('show') . '" name="' . $this->get_field_name('show') . '">';

		for ( $i = 1; $i <= 20; ++$i )
			echo "<option value='$i' " . ( $show == $i ? "selected='selected'" : '' ) . ">$i</option>";

		echo '		</select>
		</label></p>
		<p><label for="' . $this->get_field_id('hidereplies') . '"><input id="' . $this->get_field_id('hidereplies') . '" class="checkbox" type="checkbox" name="' . $this->get_field_name('hidereplies') . '"';
		if ( $hidereplies )
			echo ' checked="checked"';
		echo ' /> ' . esc_html__('Hide replies') . '</label></p>';

		echo '<p><label for="' . $this->get_field_id('includeretweets') . '"><input id="' . $this->get_field_id('includeretweets') . '" class="checkbox" type="checkbox" name="' . $this->get_field_name('includeretweets') . '"';
		if ( $include_retweets )
			echo ' checked="checked"';
		echo ' /> ' . esc_html__('Include retweets') . '</label></p>';

		echo '<p><label for="' . $this->get_field_id('beforetimesince') . '">' . esc_html__('Text to display between tweet and timestamp:') . '
		<input class="widefat" id="' . $this->get_field_id('beforetimesince') . '" name="' . $this->get_field_name('beforetimesince') . '" type="text" value="' . $before_timesince . '" />
		</label></p>';
	}

	/**
	 * Link a Twitter user mentioned in the tweet text to the user's page on Twitter.
	 *
	 * @param array $matches regex match
	 * @return string Tweet text with inserted @user link
	 */
	function _wpcom_widget_twitter_username( $matches ) { // $matches has already been through wp_specialchars
		return "$matches[1]@<a href='" . esc_url( 'http://twitter.com/' . urlencode( $matches[3] ) ) . "'>$matches[3]</a>";
	}

	/**
	 * Link a Twitter hashtag with a search results page on Twitter.com
	 *
	 * @param array $matches regex match
	 * @return string Tweet text with inserted #hashtag link
	 */
	function _wpcom_widget_twitter_hashtag( $matches ) { // $matches has already been through wp_specialchars
		return "$matches[1]<a href='" . esc_url( 'http://twitter.com/search?q=%23' . urlencode( $matches[3] ) ) . "'>#$matches[3]</a>";
	}

}

add_action( 'widgets_init', 'MO_Twitter_Oauth_Widget_Init' );
function MO_Twitter_Oauth_Widget_Init() {
	
	register_widget('MO_Twitter_Oauth_Widget');
}