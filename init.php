<?php
class mercury_fulltext extends Plugin
{
    private $host;
    
    public function about()
    {
        return array(
            2.0,
            "Try to get fulltext of the article using Self-hosted Mercury Parser API",
            "https://github.com/mitchell-guimont/mercury_fulltext"
        );
    }
    public function flags()
    {
        return array(
            "needs_curl" => true
        );
    }
    public function save()
    {
        $this
            ->host
            ->set($this, "mercury_API", $_POST["mercury_API"]);
        $this
            ->host
            ->set($this, "mercury_api_key", $_POST["mercury_api_key"]);
        echo __("Your self-hosted Mercury Parser API Endpoint has been saved.");

    }
    public function init($host)
    {
        $this->host = $host;
        
        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            return;
        }

        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);

        $host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
        
        $host->add_filter_action($this, "mercury_fulltext", __("Mercury Fulltext"));
    }

    public function get_js()
    {
        return file_get_contents(__DIR__ . "/init.js");
    }

    public function hook_article_button($line)
    {
        return "<i class='material-icons'
			style='cursor : pointer' onclick='Plugins.mercury_fulltext.extract(".$line["id"].")'
			title='".__('Extract fulltext via Mercury')."'>subject</i>";
    }


    public function hook_prefs_tab($args)
    {
        if ($args != "prefFeeds") {
            return;
        }

        print "<div dojoType='dijit.layout.AccordionPane' 
            title=\"<i class='material-icons'>extension</i> ".__('Mercury Fulltext settings (mercury_fulltext)')."\">";

        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            print_error("This plugin requires PHP 7.0.");
        } else {
            print "<h2>" . __("Per feed auto-extraction") . "</h2>";

            print_notice("Enable for specific feeds in the feed editor.");

            print "<form dojoType='dijit.form.Form'>";

            print "<script type='dojo/method' event='onSubmit' args='evt'>
                evt.preventDefault();
                if (this.validate()) {
                xhr.post(\"backend.php\", this.getValues(), (transport) => {
                            Notify.info(transport.responseText);
                        })
                }
                </script>";

            print \Controls\hidden_tag("op", "pluginhandler");
            print \Controls\hidden_tag("method", "save");
            print \Controls\hidden_tag("plugin", "mercury_fulltext");

            print "<p>";
            $mercury_API = $this
                ->host
                ->get($this, "mercury_API");

            print "<input dojoType='dijit.form.ValidationTextBox' required='1' name='mercury_API' value='$mercury_API'/>";

            print "&nbsp;<label for='mercury_API'>" . __("Your self-hosted Mercury Parser API address (including the port number), eg https://mercury.parser.com:3000.") . "</label>";
            print "</p>";
            
            print "<p>";
            $mercury_api_key = $this
                ->host
                ->get($this, "mercury_api_key");

            print "<input dojoType='dijit.form.ValidationTextBox' name='mercury_api_key' value='$mercury_api_key'/>";

            print "&nbsp;<label for='mercury_api_key'>" . __("Your self-hosted Mercury Parser API key") . "</label>";
            print "</p>";
            
            print "<p>Read the <a href='http://ttrss.henry.wang/#mercury-fulltext-extraction'>documents</a>.</p>";
            
            print "<button dojoType=\"dijit.form.Button\" type=\"submit\" class=\"alt-primary\">".__('Save')."</button>";
            print "</form>";

            $enabled_feeds = $this
                ->host
                ->get($this, "enabled_feeds");

            if (!is_array($enabled_feeds)) {
                $enabled_feeds = array();
            }

            $enabled_feeds = $this->filter_unknown_feeds($enabled_feeds);

            $this
                ->host
                ->set($this, "enabled_feeds", $enabled_feeds);

            if (count($enabled_feeds) > 0) {
                print "<h3>" . __("Currently enabled for (click to edit):") . "</h3>";

                print "<ul class='panel panel-scrollable list list-unstyled'>";

                foreach ($enabled_feeds as $f) {
                    print "<li><i class='material-icons'>rss_feed</i> <a href='#'
                        onclick='CommonDialogs.editFeed($f)'>".
                        Feeds::_get_title($f) . "</a></li>";

                }

                print "</ul>";
            }
        }
        print "</div>";
    }

    public function hook_prefs_edit_feed($feed_id)
    {
        print "<header>".__("Mercury Fulltext")."</header>";
        print "<section>";
        
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
        if (!is_array($enabled_feeds)) {
            $enabled_feeds = array();
        }
        
        $key = array_search($feed_id, $enabled_feeds);
        $checked = $key !== false ? "checked" : "";

        print "<fieldset>";
        
        print "<label class='checkbox'><input dojoType='dijit.form.CheckBox' type='checkbox' id='mercury_fulltext_enabled' name='mercury_fulltext_enabled' $checked>&nbsp;" . __('Get fulltext via Mercury Parser') . "</label>";

        print "</fieldset>";

        print "</section>";
    }

    public function hook_prefs_save_feed($feed_id)
    {
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
            
        if (!is_array($enabled_feeds)) {
            $enabled_feeds = array();
        }
        
        $enable = checkbox_to_sql_bool($_POST["mercury_fulltext_enabled"]);
        
        $key = array_search($feed_id, $enabled_feeds);
        
        if ($enable) {
            if ($key === false) {
                array_push($enabled_feeds, $feed_id);
            }
        } else {
            if ($key !== false) {
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
    public function hook_article_filter_action($article, $action)
    {
        return $this->process_article($article);
    }

    public function send_request($link)
    {
        $api_endpoint = $this
            ->host
            ->get($this, "mercury_API");
            
        $mercury_api_key = $this
            ->host
            ->get($this, "mercury_api_key");

        $ch = curl_init(rtrim($api_endpoint, '/') . '/parser?url=' . rawurlencode($link));

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
        
        # Add API key, if defined
        if ($mercury_api_key) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'x-api-key: ' . $mercury_api_key
            ));
        }

        $output = json_decode(curl_exec($ch));

        curl_close($ch);

        return $output;
    }
    
    private function fix_article_images($output)
    {
        $content = $output->content;
        $lead_image_url = $output->lead_image_url;
        
        # Only add lead image if the image is not found in article content
        if ($lead_image_url) {
            # Check if lead image name is in the query string
            if (parse_url($lead_image_url, PHP_URL_QUERY)) {
                $haystack = str_replace('&amp;', '&', $content);
                $needle = $lead_image_url;
            } else {
                $haystack = $content;
                $image_file = new SplFileInfo($lead_image_url);
                
                # The search is done with only the file name (excluding extension) because sometimes different size images have different file names/paths
                $needle = rtrim($image_file->getBasename($image_file->getExtension()), '.');
            }
            
            if (!strstr($haystack, $needle)) {
                $content = '<div><img src="' . $lead_image_url . '" /></div>' . $content;
            }
        }
        
        # Fix all images in article that have "src" equal to "data:image" and has the attribute "srcset"
        if (strstr($content, 'src="data:image') && strstr($content, 'srcset="')) {
            $domd = new DOMDocument();
            libxml_use_internal_errors(true);
            $domd->loadHTML($content);
            libxml_use_internal_errors(false);

            $domx = new DOMXPath($domd);
            $items = $domx->query("//img[@srcset]");

            foreach($items as $item) {
                $imgSrcSet = $item->getAttribute('srcset');
                $imgSrcSetArray = explode(", ", $imgSrcSet);
                
                if (is_array($imgSrcSetArray)) {
                    $lastSrcSetImg = $imgSrcSetArray[count($imgSrcSetArray) - 1];
                    $singleImgArray = explode(" ", $lastSrcSetImg);
                    
                    if (is_array($singleImgArray)) {
                        $item->setAttribute('src', $singleImgArray[0]);
                    } else {
                        $item->setAttribute('src', $singleImgArray);
                    }
                    
                    $item->removeAttribute("srcset");
                }
            }

            $content = $domd->saveHTML();
        }

        return $content;
    }
    
    public function process_article($article)
    {
        $output  = $this->send_request($article["link"]);

        if ($output->content) {
            $article["content"] = $this->fix_article_images($output);
        }

        return $article;
    }

    public function hook_article_filter($article)
    {
        $enabled_feeds = $this
            ->host
            ->get($this, "enabled_feeds");
            
        if (!is_array($enabled_feeds)) {
            return $article;
        }
        
        $key = array_search($article["feed"]["id"], $enabled_feeds);
        
        if ($key === false) {
            return $article;
        }
        
        return $this->process_article($article);
    }

    public function api_version()
    {
        return 2;
    }

    private function filter_unknown_feeds($enabled_feeds)
    {
        $tmp = array();
        
        foreach ($enabled_feeds as $feed) {
            $sth = $this
                ->pdo
                ->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
            $sth->execute([$feed, $_SESSION['uid']]);
            
            if ($row = $sth->fetch()) {
                array_push($tmp, $feed);
            }
        }

        return $tmp;
    }

    public function extract()
    {
        $article_id = (int) $_REQUEST["id"];


        $sth = $this->pdo->prepare("SELECT link FROM ttrss_entries WHERE id = ?");
        $sth->execute([$article_id]);
        
        if ($row = $sth->fetch()) {
            $output = $this->send_request($row["link"]);
        }
        $result=[];
        
        if ($output->content) {
            $result["content"] = $this->fix_article_images($output);
        }

        print json_encode($result);
    }
}
