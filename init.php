<?php class mercury_fulltext extends Plugin
{
    private $host;
    
    function about()
    {
        return array(
            2.0,
            "Try to get fulltext of the article using Self-hosted Mercury Parser API",
            "https://github.com/HenryQW/mercury_fulltext/"
        );
    }
    function flags()
    {
        return array(
            "needs_curl" => true
        );
    }
    function save()
    {
        $this
            ->host
            ->set($this, "mercury_API", $_POST["mercury_API"]);
        $this
            ->host
            ->set($this, "mercury_api_key", $_POST["mercury_api_key"]);
        echo __("Your self-hosted Mercury Parser API Endpoint.");
    }
    function init($host)
    {
        $this->host = $host;
        
        if (version_compare(PHP_VERSION, '5.6.0', '<')) {
            return;
        }

        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        
        $host->add_filter_action($this, "action_inline", __("Inline content"));
    }
    function hook_prefs_tab($args)
    {
        if ($args != "prefFeeds") return;

        print "<div dojoType='dijit.layout.AccordionPane' 
            title=\"<i class='material-icons'>extension</i> ".__('Mercury Fulltext settings (mercury_fulltext)')."\">";

        if (version_compare(PHP_VERSION, '5.6.0', '<')){
            print_error("This plugin requires PHP version 5.6.");
        }
        else {
            print "<h2>" . __("Global settings") . "</h2>";

            print_notice("Enable for specific feeds in the feed editor.");

            print "<form dojoType='dijit.form.Form'>";

            print "<script type='dojo/method' event='onSubmit' args='evt'>
                evt.preventDefault();
                if (this.validate()) {
                    console.log(dojo.objectToQuery(this.getValues()));
                    new Ajax.Request('backend.php', {
                        parameters: dojo.objectToQuery(this.getValues()),
                        onComplete: function(transport) {
                            Notify.info(transport.responseText);
                        }
                    });

                    // this.reset();

                }
                </script>";

            print_hidden("op", "pluginhandler");
            print_hidden("method", "save");
            print_hidden("plugin", "mercury_fulltext");

            $mercury_API = $this
                ->host
                ->get($this, "mercury_API");

            print "<input dojoType='dijit.form.ValidationTextBox' required='1' name='mercury_API' value='$mercury_API'/>";

            print "<label for='mercury_API'>" . __("Your self-hosted Mercury Parser API address (including the port number), eg https://mercury.parser.com:3000.") . "</label>";

            print "<p>";
            
            $mercury_api_key = $this
                ->host
                ->get($this, "mercury_api_key");

            print "<input dojoType='dijit.form.ValidationTextBox' name='mercury_api_key' value='$mercury_api_key'/>";

            print "<label for='mercury_api_key'>" . __("Your self-hosted Mercury Parser API key") . "</label>";

            print "<p>";
            print print_button("submit", __("Save"), "class='alt-primary'");
            print "</form>";

            $enabled_feeds = $this
                ->host
                ->get($this, "enabled_feeds");

            if (!is_array($enabled_feeds)) $enabled_feeds = array();

            $enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);

            $this
                ->host
                ->set($this, "enabled_feeds", $enabled_feeds);

            if (count($enabled_feeds) > 0)
            {
                print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

                print "<ul class='panel panel-scrollable list list-unstyled'>";

                foreach ($enabled_feeds as $f) {
                    print "<li><i class='material-icons'>rss_feed</i> <a href='#'
                        onclick='CommonDialogs.editFeed($f)'>".
                        Feeds::getFeedTitle($f) . "</a></li>";
                }

                print "</ul>";
            }
        }
        print "</div>";
    }

    function hook_prefs_edit_feed($feed_id)
    {
        print "<header>".__("Mercury Fulltext")."</header>";
        print "<section>";
        
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) $enabled_feeds = array();
        
        $key = array_search($feed_id, $enabled_feeds);
        $checked = $key !== false ? "checked" : "";

        print "<fieldset>";
        
        print "<label class='checkbox'><input dojoType='dijit.form.CheckBox' type='checkbox' id='mercury_fulltext_enabled' name='mercury_fulltext_enabled' $checked>&nbsp;" . __('Get fulltext via Mercury Parser') . "</label>";

        print "</fieldset>";

        print "</section>";
    }

    function hook_prefs_save_feed($feed_id)
    {
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
            
        if (!is_array($enabled_feeds)) $enabled_feeds = array();
        
        $enable = checkbox_to_sql_bool($_POST["mercury_fulltext_enabled"]);
        
        $key = array_search($feed_id, $enabled_feeds);
        
        if ($enable)
        {
            if ($key === false)
            {
                array_push($enabled_feeds, $feed_id);
            }
        }
        else
        {
            if ($key !== false)
            {
                unset($enabled_feeds[$key]);
            }
        }

        $this
            ->host
            ->set($this, "enabled_feeds", $enabled_feeds);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    function hook_article_filter_action($article, $action)
    {
        return $this->process_article($article);
    }

    function process_article($article)
    {
        $ch = curl_init();
        
        $url = $article['link'];
        
        $api_endpoint = $this
            ->host
            ->get($this, "mercury_API");
        
        $mercury_api_key = $this
            ->host
            ->get($this, "mercury_api_key");
            
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, rtrim($api_endpoint, '/') . '/parser?url=' . rawurlencode($url));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
        
        # Add AWS API key, if defined
        if ($mercury_api_key) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'x-api-key: ' . $mercury_api_key
            ));
        }
        
        $output = json_decode(curl_exec($ch));
        
        curl_close($ch);
        
        $extracted_content = $output->content;
        $lead_image_url = $output->lead_image_url;
        
        if ($extracted_content)
        {
            # Only add lead image if the image is not found in article content
            if ($lead_image_url)
            {
                # Check if lead image name is in the query string
                if (parse_url($lead_image_url, PHP_URL_QUERY)) 
                {
                    $haystack = str_replace('&amp;', '&', $extracted_content);
                    $needle = $lead_image_url;
                } else 
                {
                    $haystack = $extracted_content;
                    $image_file = new SplFileInfo($lead_image_url);
                    
                    # The search is done with only the file name (excluding extension) because sometimes different size images have different file names/paths
                    $needle = rtrim($image_file->getBasename($image_file->getExtension()), '.');
                }
                
                if (!strstr($haystack, $needle)) 
                {
                    $extracted_content = '<div><img src="' . $lead_image_url . '" /></div>' . $extracted_content;
                } else if (strstr($haystack, 'src="data:image') && strstr($haystack, 'srcset="')) {
                    # If an image "src" is "data:image" and has the attribute "srcset" then set "srcset" as the "src"
                    $domd = new DOMDocument();
                    libxml_use_internal_errors(true);
                    $domd->loadHTML($extracted_content);
                    libxml_use_internal_errors(false);

                    $domx = new DOMXPath($domd);
                    $items = $domx->query("//img[@srcset]");

                    foreach($items as $item) {
                        // Only make this change on the lead image
                        if (strstr($domd->saveHTML($item), $needle)) {
                          $item->removeAttribute("srcset");
                          $item->setAttribute('src', $lead_image_url);
                        }
                    }

                    $extracted_content = $domd->saveHTML();
                }
            }
            
            # Fix all images in article that have "src" equal to "data:image" and has the attribute "srcset"
            if (strstr($haystack, 'src="data:image') && strstr($haystack, 'srcset="')) {
                $domd = new DOMDocument();
                libxml_use_internal_errors(true);
                $domd->loadHTML($extracted_content);
                libxml_use_internal_errors(false);

                $domx = new DOMXPath($domd);
                $items = $domx->query("//img[@srcset]");

                foreach($items as $item) {
                    $imgSrcSet = $item->getAttribute('srcset');
                    $imgSrcSetArray = explode(", ", $imgSrcSet);
                    $item->setAttribute('imgSrcSetArray-count', count($imgSrcSetArray));
                    $item->setAttribute('imgSrcSetArray2', $imgSrcSetArray[count($imgSrcSetArray) - 1]);
                    $singleImgArray = explode(" ", $imgSrcSetArray[count($imgSrcSetArray) - 1]);
                    $item->setAttribute('src', $singleImgArray[0]);
                    $item->removeAttribute("srcset");
                }

                $extracted_content = $domd->saveHTML();
            }
            
            $article["content"] = $extracted_content;
        }

        return $article;
    }

    function hook_article_filter($article)
    {
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
            
        if (!is_array($enabled_feeds)) return $article;
        
        $key = array_search($article["feed"]["id"], $enabled_feeds);
        
        if ($key === false) return $article;
        
        return $this->process_article($article);
    }

    function api_version()
    {
        return 2;
    }

    private function filter_unknown_feeds($enabled_feeds)
    {
        $tmp = array();
        
        foreach ($enabled_feeds as $feed)
        {
            $sth = $this
                ->pdo
                ->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
            $sth->execute([$feed, $_SESSION['uid']]);
            
            if ($row = $sth->fetch())
            {
                array_push($tmp, $feed);
            }
        }

        return $tmp;
    }
}

