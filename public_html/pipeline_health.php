<?php
$title = 'Repository health';
$subtitle = 'Check GitHub settings for all nf-core repositories';
$mainpage_container = false;
include('../includes/header.php');

// Refresh cache?
function is_refresh_cache($repo = null, $any_repo = false){
  if(!isset($_GET['action']) || $_GET['action'] != 'refresh')
    return false;
  if($any_repo || (isset($_GET['repos']) && $_GET['repos'] == 'all') )
    return true;
  if($repo && isset($_GET['repos']) && $_GET['repos'] == $repo)
    return true;
  return false;
}

// Fix repo?
function is_fix_repo($repo = null){
  if(!isset($_GET['action']) || $_GET['action'] != 'fix')
    return false;
  if(isset($_GET['repos']) && $_GET['repos'] == 'all')
    return true;
  if($repo && isset($_GET['repos']) && $_GET['repos'] == $repo)
    return true;
  return false;
}

// Get auth secrets
$config = parse_ini_file("../config.ini");
define('GH_AUTH', base64_encode($config['github_username'].':'.$config['github_access_token']));

// Load pipelines JSON
$pipelines_json = json_decode(file_get_contents('pipelines.json'))->remote_workflows;

// Placeholders
$pipelines = [];
$core_repos = [];

// HTTP header to use on GitHub API GET requests
define('GH_API_OPTS',
  stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => [
        'User-Agent: PHP',
        'Accept:application/vnd.github.mercy-preview+json', // Needed to get topics (keywords) for now
        'Accept:application/vnd.github.luke-cage-preview+json', // Needed to get protected branch required reviews
        "Authorization: Basic ".GH_AUTH
      ]
    ]
  ])
);

// Base repo health class
class RepoHealth {

  // Init vars
  public $name;
  public $refresh = false;
  public $cache_base;
  public function __construct($name) {
    $this->name = $name;
    $this->refresh = is_refresh_cache($this->name);

    // Cache filenames
    $this->cache_base = dirname(dirname(__FILE__)).'/api_cache/pipeline_health';
    $this->gh_repo_cache = $this->cache_base.'/repo_'.$this->name.'.json';
    $this->gh_release_cache = $this->cache_base.'/release_'.$this->name.'.json';
    $this->gh_all_branches_cache = $this->cache_base.'/branches_'.$this->name.'.json';
    $this->gh_webpage_cache = $this->cache_base.'/repo_ghpage_'.$this->name.'.html';
  }

  // Names of required CI checks. These are added to whatever already exists.
  public $required_status_check_contexts = [
    'Markdown',
    'YAML',
    'nf-core',
    'Run workflow tests'
    // NOTE - doesn't seem to be any way to get the "available" contexts through GitHub API
    // If we really want to do this, might have to query the repo contents..??
  ];

  // Names of old CI tests that must not be present any more
  public $required_remove_status_check_contexts = [
    'continuous-integration/travis-ci',
    'test'
  ];
  public $branch_exist_tests = ['master'];
  public $branches_protection = ['master'];
  public $branch_default = 'master';
  public $required_topics = ['nf-core'];
  public $web_url = 'https://nf-co.re';
  public $test_names;
  public $test_descriptions;
  public $test_urls;

  // Data vars
  public $gh_repo;
  public $gh_release;
  public $gh_teams = [];
  public $gh_branches;
  public $gh_branch_master;
  public $gh_branch_dev;
  public $gh_webpage;
  public $gh_social_preview;

  // Test result variables
  public $repo_wikis;
  public $repo_issues;
  public $repo_merge_commits;
  public $repo_merge_rebase;
  public $repo_merge_squash;
  public $repo_default_branch;
  public $repo_keywords;
  public $repo_description;
  public $repo_url;
  public $social_preview;
  public $team_all;
  public $team_core;

  // Branch test vars
  public $branch_master_exists;
  public $branch_dev_exists;
  public $branch_template_exists;
  public $branch_master_strict_updates;
  public $branch_master_required_ci;
  public $branch_master_stale_reviews;
  public $branch_master_code_owner_reviews;
  public $branch_master_required_num_reviews;
  public $branch_master_enforce_admins;
  public $branch_dev_strict_updates;
  public $branch_dev_required_ci;
  public $branch_dev_stale_reviews;
  public $branch_dev_code_owner_reviews;
  public $branch_dev_required_num_reviews;
  public $branch_dev_enforce_admins;

  public function get_data(){
    $this->get_repo_data();
    $this->get_branch_data();
    $this->get_repo_webpage();
  }
  public function run_tests(){
    $this->test_repo();
    $this->test_teams();
    $this->test_branch_exists();
    $this->test_webpage();
  }
  public function fix_tests(){
    if(is_fix_repo($this->name)){
      $this->fix_repo();
      $this->fix_topics();
      $this->fix_teams();
      // Done! Refresh the test statuses
      $this->run_tests();
    }
  }

  public function get_repo_data(){
    // Super annoyingly, the teams call misses just one or two keys we need :(
    if(is_null($this->gh_repo) || !isset($this->gh_repo->allow_merge_commit)){
      if(file_exists($this->gh_repo_cache) && !$this->refresh){
        $this->gh_repo = json_decode(file_get_contents($this->gh_repo_cache));
      } else {
        $gh_repo_url = 'https://api.github.com/repos/nf-core/'.$this->name;
        $this->gh_repo = json_decode(file_get_contents($gh_repo_url, false, GH_API_OPTS));
        $this->_save_cache_data($this->gh_repo_cache, $this->gh_repo);
      }
    }
  }

  public function get_release_data(){
    // Currently only used to get last release for tools, as have otheres from pipelines.json
    if(file_exists($this->gh_release_cache) && !$this->refresh){
      $this->gh_release = json_decode(file_get_contents($this->gh_release_cache));
    } else {
      $gh_release_url = 'https://api.github.com/repos/nf-core/'.$this->name.'/releases/latest';
      $this->gh_release = json_decode(file_get_contents($gh_release_url, false, GH_API_OPTS));
      $this->_save_cache_data($this->gh_release_cache, $this->gh_release);
    }
  }

  public function get_branch_data(){

    // List of all branches
    if(file_exists($this->gh_all_branches_cache) && !$this->refresh){
      $this->gh_branches = json_decode(file_get_contents($this->gh_all_branches_cache));
    } else {
      $gh_branch_url = 'https://api.github.com/repos/nf-core/'.$this->name.'/branches';
      $this->gh_branches = json_decode(@file_get_contents($gh_branch_url, false, GH_API_OPTS));
      $this->_save_cache_data($this->gh_all_branches_cache, $this->gh_branches);
    }

    // Details of branch protection for master and dev
    foreach(['master', 'dev'] as $branch){
      $gh_branch_cache = $this->cache_base.'/branch_'.$this->name.'_'.$branch.'.json';
      if(file_exists($gh_branch_cache) && !$this->refresh){
        $gh_branch = json_decode(file_get_contents($gh_branch_cache));
        if(is_object($gh_branch)){
          $this->{'gh_branch_'.$branch} = $gh_branch;
        } else {
        }
      } else {
        $gh_branch_url = 'https://api.github.com/repos/nf-core/'.$this->name.'/branches/'.$branch.'/protection';
        $gh_branch = json_decode(@file_get_contents($gh_branch_url, false, GH_API_OPTS));
        if(in_array("HTTP/1.1 200 OK", $http_response_header) && is_object($gh_branch)){
          $this->{'gh_branch_'.$branch} = $gh_branch;
          $this->_save_cache_data($gh_branch_cache, $this->{'gh_branch_'.$branch});
        } else {
          // Write an empty cache file
          $this->_save_cache_data($gh_branch_cache, '');
        }
      }
    }
  }

  public function get_repo_webpage(){

    // List of all branches
    if(file_exists($this->gh_webpage_cache) && !$this->refresh){
      $this->gh_webpage = file_get_contents($this->gh_webpage_cache);
    } else {
      $gh_webpage_url = 'https://github.com/nf-core/'.$this->name;
      $this->gh_webpage = @file_get_contents($gh_webpage_url);
      $this->_save_cache_data($this->gh_webpage_cache, $this->gh_webpage, false);
    }

    // Pull out social image
    preg_match('/<meta name="twitter:image:src" content="([^"]+)" \/>/', $this->gh_webpage, $social_matches);
    if(array_key_exists(1, $social_matches)){
      $this->gh_social_preview = $social_matches[1];
    }

  }

  public function test_topics(){
    $topics_pass = true;
    foreach($this->required_topics as $top){
      if(!in_array($top, $this->gh_repo->topics)){
        $topics_pass = false;
        break;
      }
    }
    return $topics_pass;
  }
  public function test_repo(){
    if(isset($this->gh_repo->has_wiki)) $this->repo_wikis = !$this->gh_repo->has_wiki;
    if(isset($this->gh_repo->has_issues)) $this->repo_issues = $this->gh_repo->has_issues;
    if(isset($this->gh_repo->allow_merge_commit)) $this->repo_merge_commits = $this->gh_repo->allow_merge_commit;
    if(isset($this->gh_repo->allow_rebase_merge)) $this->repo_merge_rebase = $this->gh_repo->allow_rebase_merge;
    if(isset($this->gh_repo->allow_squash_merge)) $this->repo_merge_squash = !$this->gh_repo->allow_squash_merge;
    if(isset($this->gh_repo->default_branch)) $this->repo_default_branch = $this->gh_repo->default_branch == $this->branch_default;
    if(isset($this->gh_repo->topics)) $this->repo_keywords = $this->test_topics();
    if(isset($this->gh_repo->description)) $this->repo_description = $this->gh_repo->description;
    if(isset($this->gh_repo->homepage)) $this->repo_url = $this->gh_repo->homepage == $this->web_url;
  }
  public function test_teams(){
    $this->team_all = isset($this->gh_teams['all']) ? $this->gh_teams['all']->push : false;
    $this->team_core = isset($this->gh_teams['core']) ? $this->gh_teams['core']->admin : false;
  }
  public function test_branch_exists(){
    // Check that branches exist
    if(isset($this->gh_branches)){
      $this->branch_master_exists = false;
      $this->branch_dev_exists = false;
      $this->branch_template_exists = false;
      foreach($this->gh_branches as $branch){
        if(in_array(strtolower($branch->name), $this->branch_exist_tests)){
          $this->{'branch_'.strtolower($branch->name).'_exists'} = true;
        }
      }
      // Check that the branch that should be default actually exists
      if(!$this->{'branch_'.strtolower($this->branch_default).'_exists'}){
        $this->repo_default_branch = -1;
      }
    }
  }
  public function test_branch_protection(){
    // Test branch protection for master and dev
    foreach ($this->branches_protection as $branch) {
      $prs_required = $branch == 'master' ? 2 : 1;
      if(!$this->{'branch_'.$branch.'_exists'}){
        $this->{'branch_'.$branch.'_strict_updates'} = -1;
        $this->{'branch_'.$branch.'_required_ci'} = -1;
        $this->{'branch_'.$branch.'_stale_reviews'} = -1;
        $this->{'branch_'.$branch.'_code_owner_reviews'} = -1;
        $this->{'branch_'.$branch.'_required_num_reviews'} = -1;
        $this->{'branch_'.$branch.'_enforce_admins'} = -1;
        $this->test_descriptions['branch_'.$branch.'_strict_updates'] = $branch.' branch does not exist';
        $this->test_descriptions['branch_'.$branch.'_required_ci'] = $branch.' branch does not exist';
        $this->test_descriptions['branch_'.$branch.'_stale_reviews'] = $branch.' branch does not exist';
        $this->test_descriptions['branch_'.$branch.'_code_owner_reviews'] = $branch.' branch does not exist';
        $this->test_descriptions['branch_'.$branch.'_required_num_reviews'] = $branch.' branch does not exist';
        $this->test_descriptions['branch_'.$branch.'_enforce_admins'] = $branch.' branch does not exist';
        continue;
      }
      $data = $this->{'gh_branch_'.$branch};

      if(!isset($data->required_status_checks)){
        $this->{'branch_'.$branch.'_strict_updates'} = false;
        $this->{'branch_'.$branch.'_required_ci'} = false;
      } else {
        // Don't require branches to be up to date before merging.
        $this->{'branch_'.$branch.'_strict_updates'} = $data->required_status_checks->strict == false;
        // At least the minimum set of required CI tests
        $has_min_ci_checks = !array_diff($this->required_status_check_contexts, $data->required_status_checks->contexts);
        // None of the required tests that we don't want
        $has_removed_ci_checks = count(array_diff($this->required_remove_status_check_contexts, $data->required_status_checks->contexts)) == count($this->required_remove_status_check_contexts);
        // Only pass if both of the above pass
        $this->{'branch_'.$branch.'_required_ci'} = $has_min_ci_checks && $has_removed_ci_checks;
      }
      if(!isset($data->required_pull_request_reviews)){
        $this->{'branch_'.$branch.'_stale_reviews'} = false;
        $this->{'branch_'.$branch.'_code_owner_reviews'} = false;
        $this->{'branch_'.$branch.'_required_num_reviews'} = false;
      } else {
        // Don't mark reviews as stale on new commits
        $this->{'branch_'.$branch.'_stale_reviews'} = $data->required_pull_request_reviews->dismiss_stale_reviews == false;
        // Don't require reviews from code owners
        $this->{'branch_'.$branch.'_code_owner_reviews'} = $data->required_pull_request_reviews->require_code_owner_reviews == false;
        // Require 1 or 2 reviews
        $this->{'branch_'.$branch.'_required_num_reviews'} = $data->required_pull_request_reviews->required_approving_review_count == $prs_required;
      }
      // Don't include administrators
      if(!isset($data->enforce_admins)) $this->{'branch_'.$branch.'_enforce_admins'} = false;
      else $this->{'branch_'.$branch.'_enforce_admins'} = $data->enforce_admins->enabled == false;

    }
  }

  public function test_webpage(){
    if(isset($this->gh_webpage)){
      $startswith = 'https://repository-images.githubusercontent.com';
      $this->social_preview = substr($this->gh_social_preview, 0, strlen($startswith)) == $startswith;
    }
  }


  private function fix_repo(){
    // https://developer.github.com/v3/repos/#edit
    $payload = array();
    if(!$this->repo_wikis) $payload['has_wiki'] = false;
    if(!$this->repo_issues) $payload['has_issues'] = true;
    if(!$this->repo_merge_commits) $payload['allow_merge_commit'] = true;
    if(!$this->repo_merge_rebase) $payload['allow_rebase_merge'] = true;
    if(!$this->repo_merge_squash) $payload['allow_squash_merge'] = false;
    if(!$this->repo_default_branch) $payload['default_branch'] = $this->branch_default;
    if(!$this->repo_url) $payload['homepage'] = $this->web_url;
    if(count($payload) > 0){
      $gh_edit_repo_url = 'https://api.github.com/repos/nf-core/'.$this->name;
      $updated_data = $this->_send_gh_api_data($gh_edit_repo_url, $payload, 'PATCH');
      if($updated_data){
        $this->gh_repo = $updated_data;
        $this->_save_cache_data($this->gh_repo_cache, $this->gh_repo);
      }
    }
  }

  private function fix_topics(){
    // https://developer.github.com/v3/repos/#replace-all-topics-for-a-repository
    if(!$this->repo_keywords){
      $topics = array( 'names' => array_values(array_unique(array_merge($this->gh_repo->topics, $this->required_topics))) );
      $gh_edit_topics_url = 'https://api.github.com/repos/nf-core/'.$this->name.'/topics';
      $updated_data = $this->_send_gh_api_data($gh_edit_topics_url, $topics, 'PUT');
      if($updated_data){
        $this->gh_repo->topics = $updated_data->names;
        $this->_save_cache_data($this->gh_repo_cache, $this->gh_repo);
      }
    }
  }

  private function fix_teams(){
    $this->fix_team('all');
    $this->fix_team('core');
  }
  private function fix_team($team){
    global $gh_team_ids;
    global $updated_teams;
    if(!$this->{'team_'.$team}){
      $payload = array();
      if($team == 'core') $payload = array('permission' => 'admin');
      if($team == 'all') $payload = array('permission' => 'push');
      $gh_edit_team_url = 'https://api.github.com/teams/'.$gh_team_ids[$team].'/repos/nf-core/'.$this->name;
      if($this->_send_gh_api_data($gh_edit_team_url, $payload, 'PUT')){
        $updated_teams[$team] = true;
      }
    }
  }

  public function fix_branch_protection(){
    // Fix branch protection for master and dev
    foreach ($this->branches_protection as $branch) {
      // Convenience vars for test results
      $test_results = [
        $this->{'branch_'.$branch.'_enforce_admins'},
        $this->{'branch_'.$branch.'_strict_updates'},
        $this->{'branch_'.$branch.'_required_ci'},
        $this->{'branch_'.$branch.'_stale_reviews'},
        $this->{'branch_'.$branch.'_code_owner_reviews'},
        $this->{'branch_'.$branch.'_required_num_reviews'},
      ];

      // Only run if we have at least one test failure
      if(count(array_keys($test_results, true)) != count($test_results)){
        // Add needed required-CI tests to what's already there if we have something
        if(is_object($this->{'gh_branch_'.$branch}) && isset($this->{'gh_branch_'.$branch}->required_status_checks)){
          $contexts = array_values( array_unique( array_merge(
            $this->required_status_check_contexts,
            $this->{'gh_branch_'.$branch}->required_status_checks->contexts
          )));
        } else {
          $contexts = $this->required_status_check_contexts;
        }
        // Remove any old contexts that we don't want
        $contexts = array_diff($contexts, $this->required_remove_status_check_contexts);

        $payload = array(
          "enforce_admins" => false,
          "required_status_checks" => array(
            "strict" => false,
            "contexts" => $contexts,
          ),
          "required_pull_request_reviews" => array(
            "dismiss_stale_reviews" => false,
            "require_code_owner_reviews" => false,
            "required_approving_review_count" => $branch == 'master' ? 2 : 1,
          ),
          "restrictions" => null
        );
        $gh_edit_branch_protection_url = 'https://api.github.com/repos/nf-core/'.$this->name.'/branches/'.$branch.'/protection';
        $updated_data = $this->_send_gh_api_data($gh_edit_branch_protection_url, $payload, 'PUT');
        if($updated_data){
          $this->{'gh_branch_'.$branch} = $updated_data;
          $gh_branch_cache = $this->cache_base.'/branch_'.$this->name.'_'.$branch.'.json';
          $this->_save_cache_data($gh_branch_cache, $this->{'gh_branch_'.$branch});
        }
      }
    }
  }



  public function _send_gh_api_data($url, $content, $method='POST'){
    $context = stream_context_create([
      'http' => [
        'method' => $method,
        'header' => [
          'Content-Type: application/json',
          'User-Agent: PHP',
          'Accept:application/vnd.github.mercy-preview+json', // Needed to get topics (keywords) for now
          'Accept:application/vnd.github.luke-cage-preview+json', // Needed to get protected branch required reviews
          "Authorization: Basic ".GH_AUTH
        ],
        'content' => json_encode($content)
      ]
    ]);
    $result = json_decode(file_get_contents($url, false, $context));
    if(in_array("HTTP/1.1 204 No Content", $http_response_header)){
      return true;
    } else if(in_array("HTTP/1.1 200 OK", $http_response_header)){
      return $result;
    } else {
      echo '<div class="alert alert-danger m-3">';
        echo '<strong class="mr-2">Error with GitHub API</strong> ';
        echo 'There was a problem with the following URL: <code class="mr-2">'.$url.'</code> (<code>'.$method.'</code>)';
        echo '<br><span class="text-muted small">See the browser console for details</span>';
        echo '<script>console.log('.$url.', '.json_encode($content).', '.json_encode($http_response_header).'); </script>';
      echo '</div>';
      return false;
    }
  }

  private function _save_cache_data($path, $data, $encode_json=true){
    if (!file_exists(dirname($path))) mkdir(dirname($path), 0777, true);
    if($encode_json) $data_json = json_encode($data, JSON_PRETTY_PRINT)."\n";
    else $data_json = $data;
    file_put_contents($path, $data_json);
  }

  public function print_table_cell($test_name){
    $test_url = $this->test_urls[$test_name];
    $test_url = str_replace('{repo}', $this->name, $test_url);
    if(is_null($this->$test_name)){
      echo '<td class="table-secondary text-center" title="<strong>'.$this->name.':</strong> '.$this->test_descriptions[$test_name].'" data-toggle="tooltip" data-html="true">
        <a href="'.$test_url.'" class="d-block" target="_blank"><i class="fas fa-question text-secondary"></i></a>
      </td>';
    } else if($this->$test_name === -1){
      echo '<td class="table-secondary text-center" title="<strong>'.$this->name.':</strong> '.$this->test_descriptions[$test_name].'" data-toggle="tooltip" data-html="true">
        <a href="'.$test_url.'" class="d-block" target="_blank"><i class="fas fa-times text-secondary"></i></a>
      </td>';
    } else if($this->$test_name){
      echo '<td class="table-success text-center" title="<strong>'.$this->name.':</strong> '.$this->test_descriptions[$test_name].'" data-toggle="tooltip" data-html="true">
        <a href="'.$test_url.'" class="d-block" target="_blank"><i class="fas fa-check text-success"></i></a>
      </td>';
    } else {
      echo '<td class="table-danger text-center" title="<strong>'.$this->name.':</strong> '.$this->test_descriptions[$test_name].'" data-toggle="tooltip" data-html="true">
        <a href="'.$test_url.'" class="d-block" target="_blank"><i class="fas fa-times text-danger"></i></a>
      </td>';
    }
  }
}

// Pipeline health class
class PipelineHealth extends RepoHealth {
  // URL should point to pipeline page
  public function __construct($name) {
    parent::__construct($name);
    $this->web_url = 'https://nf-co.re/'.$this->name;
  }
  // We need more branches in pipelines
  public $branch_exist_tests = ['template', 'dev', 'master']; // lower case
  public $branches_protection = ['dev', 'master'];
  // Keywords should also include nextflow, workflow and pipeline
  public $required_topics = ['nf-core', 'nextflow', 'workflow', 'pipeline'];
  // Variables for release tests
  public $has_release;
  public $last_release;
  public $release_after_tools;

  // Extra pipeline-specific tests
  public function run_tests(){
    parent::run_tests();
    $this->test_branch_protection();
    $this->test_releases();
  }

  public function test_releases(){
    global $tools_last_release;
    if(!$this->has_release) $this->release_after_tools = -1;
    else if($this->last_release && $tools_last_release){
      $this->release_after_tools = strtotime($this->last_release) > strtotime($tools_last_release);
    }
  }

  // Extra pipeline-specific fixes
  public function fix_tests(){
    parent::fix_tests();
    if(is_fix_repo($this->name)){
      $this->fix_branch_protection();
      // Done! Refresh the test statuses
      $this->run_tests();
    }
  }
}

// Core repo health class
class CoreRepoHealth extends RepoHealth {

}

// Get nf-core GitHub teams info & repos
function get_gh_team_repos($team){
  // Globals
  global $pipelines_json;
  global $pipelines;
  global $core_repos;
  global $gh_team_ids;

  // Get team ID
  $gh_teams_cache = dirname(dirname(__FILE__)).'/api_cache/pipeline_health/team_'.$team.'.json';
  if(file_exists($gh_teams_cache) && !is_refresh_cache(null, true)){
    $gh_team = json_decode(file_get_contents($gh_teams_cache));
  } else {
    $gh_team_url = 'https://api.github.com/orgs/nf-core/teams/'.$team;
    $gh_team = json_decode(file_get_contents($gh_team_url, false, GH_API_OPTS));

    // Save for next time
    if (!file_exists(dirname($gh_teams_cache))) mkdir(dirname($gh_teams_cache), 0777, true);
    $gh_team_json = json_encode($gh_team, JSON_PRETTY_PRINT)."\n";
    file_put_contents($gh_teams_cache, $gh_team_json);
  }
  $gh_team_ids[$team] = $gh_team->id;

  $gh_team_repos_cache = dirname(dirname(__FILE__)).'/api_cache/pipeline_health/team_'.$team.'_repos.json';
  if(file_exists($gh_team_repos_cache) && !is_refresh_cache(null, true)){
    $gh_team_repos = json_decode(file_get_contents($gh_team_repos_cache));
  } else {
    $gh_team_repos_url = 'https://api.github.com/teams/'.$gh_team->id.'/repos';
    $first_page = true;
    $next_page = false;
    $gh_team_repos = [];
    while($first_page || $next_page){

      // reset loop vars
      $first_page = false;
      // Get GitHub API results
      if($next_page){
        $gh_team_repos_url = $next_page;
      }
      $gh_team_repos = array_merge($gh_team_repos, json_decode(file_get_contents($gh_team_repos_url, false, GH_API_OPTS)));

      // Look for URL to next page of API results
      $next_page = false;
      $m_array = preg_grep('/rel="next"/', $http_response_header);
      if(count($m_array) > 0){
        preg_match('/<([^>]+)>; rel="next"/', array_values($m_array)[0], $matches);
        if(isset($matches[1])){
          $next_page = $matches[1];
        }
      }
    }

    // Save for next time
    if (!file_exists(dirname($gh_team_repos_cache))) mkdir(dirname($gh_team_repos_cache), 0777, true);
    $gh_team_repos_json = json_encode($gh_team_repos, JSON_PRETTY_PRINT)."\n";
    file_put_contents($gh_team_repos_cache, $gh_team_repos_json);
  }

  // Make repo health objects
  foreach($gh_team_repos as $repo){
    if($repo->archived) continue;
    // Make a pipeline object
    $is_pipeline = false;
    foreach($pipelines_json as $wf){
      if($wf->name == $repo->name){
        if(!array_key_exists($repo->name, $pipelines)){
          $pipelines[$repo->name] = new PipelineHealth($repo->name);
          $pipelines[$repo->name]->gh_repo = $repo;
        }
        $pipelines[$repo->name]->gh_teams[$team] = $repo->permissions;
        $is_pipeline = true;
      }
    }
    // Make a core repo object
    if(!$is_pipeline){
      if(!array_key_exists($repo->name, $core_repos)){
        $core_repos[$repo->name] = new CoreRepoHealth($repo->name);
        $core_repos[$repo->name]->gh_repo = $repo;
      }
      $core_repos[$repo->name]->gh_teams[$team] = $repo->permissions;
    }
  }
}
$gh_team_ids = [];
get_gh_team_repos('all');
get_gh_team_repos('core');

// Loop through pipelines
foreach($pipelines_json as $wf){
  // Remove archived pipelines
  if($wf->archived){
    if(array_key_exists($wf->name, $pipelines)){
      unset($pipelines[$wf->name]);
    }
  } else {
    // Add, in case there are any without team access
    if(!array_key_exists($wf->name, $pipelines)){
      $pipelines[$wf->name] = new PipelineHealth($wf->name);
    }
    // Add data for release tests
    $pipelines[$wf->name]->has_release = false;
    if(count($wf->releases) > 0){
      $pipelines[$wf->name]->has_release = true;
      $pipelines[$wf->name]->last_release = end($wf->releases)->published_at;
    } else {
      $pipelines[$wf->name]->branch_default = 'dev';
    }
  }
}

$base_test_names = [
  'repo_wikis' => "Wikis",
  'repo_issues' => "Issues",
  'repo_merge_commits' => "Merge commits",
  'repo_merge_rebase' => "Rebase merging",
  'repo_merge_squash' => "Squash merges",
  'repo_default_branch' => "Default branch",
  'repo_keywords' => "Keywords",
  'repo_description' => "Description",
  'repo_url' => "Repo URL",
  'social_preview' => "Social preview",
  'team_all' => "Team all",
  'team_core' => "Team core",
  'branch_master_exists' => 'master: exists',
  'branch_dev_exists' => 'dev: exists',
  'branch_template_exists' => 'TEMPLATE: exists',
  'branch_master_strict_updates' => 'master: strict updates',
  'branch_master_required_ci' => 'master: required CI',
  'branch_master_stale_reviews' => 'master: stale reviews',
  'branch_master_code_owner_reviews' => 'master: code owner reviews',
  'branch_master_required_num_reviews' => 'master: 2 reviews',
  'branch_master_enforce_admins' => 'master: enforce admins',
  'branch_dev_strict_updates' => 'dev: strict updates',
  'branch_dev_required_ci' => 'dev: required CI',
  'branch_dev_stale_reviews' => 'dev: stale reviews',
  'branch_dev_code_owner_reviews' => 'dev: code owner reviews',
  'branch_dev_required_num_reviews' => 'dev: 1 review',
  'branch_dev_enforce_admins' => 'dev: enforce admins',
];
$base_test_descriptions = [
  'repo_wikis' => "Disable wikis",
  'repo_issues' => "Enable issues",
  'repo_merge_commits' => "Allow merge commits",
  'repo_merge_rebase' => "Allow rebase merging",
  'repo_merge_squash' => "Do not allow squash merges",
  'repo_default_branch' => "default branch master (released) or dev (no releases)",
  'repo_keywords' => "Minimum keywords set",
  'repo_description' => "Description must be set",
  'repo_url' => "URL should be set to https://nf-co.re",
  'social_preview' => "Repo should have a social preview image set",
  'team_all' => "Write access for nf-core/all",
  'team_core' => "Admin access for nf-core/core",
  'branch_master_exists' => 'master branch: branch must exist',
  'branch_dev_exists' => 'dev branch: branch must exist',
  'branch_template_exists' => 'TEMPLATE branch: branch must exist',
  'branch_master_strict_updates' => 'master branch: do not require branch to be up to date before merging',
  'branch_master_required_ci' => 'master branch: minimum set of CI tests must pass',
  'branch_master_stale_reviews' => 'master branch: reviews not marked stale after new commits',
  'branch_master_code_owner_reviews' => 'master branch: code owner reviews not required',
  'branch_master_required_num_reviews' => 'master branch: 2 reviews required',
  'branch_master_enforce_admins' => 'master branch: do not enforce rules for admins',
  'branch_dev_strict_updates' => 'dev branch: do not require branch to be up to date before merging',
  'branch_dev_required_ci' => 'dev branch: minimum set of CI tests must pass',
  'branch_dev_stale_reviews' => 'dev branch: reviews not marked stale after new commits',
  'branch_dev_code_owner_reviews' => 'dev branch: code owner reviews not required',
  'branch_dev_required_num_reviews' => 'dev branch: 1 review required',
  'branch_dev_enforce_admins' => 'dev branch: do not enforce rules for admins',
];
$base_test_urls = [
  'repo_wikis' =>                         'https://github.com/nf-core/{repo}/settings',
  'repo_issues' =>                        'https://github.com/nf-core/{repo}/settings',
  'repo_merge_commits' =>                 'https://github.com/nf-core/{repo}/settings',
  'repo_merge_rebase' =>                  'https://github.com/nf-core/{repo}/settings',
  'repo_merge_squash' =>                  'https://github.com/nf-core/{repo}/settings',
  'repo_default_branch' =>                'https://github.com/nf-core/{repo}/settings/branches',
  'repo_keywords' =>                      'https://github.com/nf-core/{repo}',
  'repo_description' =>                   'https://github.com/nf-core/{repo}',
  'repo_url' =>                           'https://github.com/nf-core/{repo}',
  'social_preview' =>                     'https://github.com/nf-core/{repo}/settings',
  'team_all' =>                           'https://github.com/nf-core/{repo}/settings/collaboration',
  'team_core' =>                          'https://github.com/nf-core/{repo}/settings/collaboration',
  'branch_master_exists' =>               'https://github.com/nf-core/{repo}/branches',
  'branch_dev_exists' =>                  'https://github.com/nf-core/{repo}/branches',
  'branch_template_exists' =>             'https://github.com/nf-core/{repo}/branches',
  'branch_master_strict_updates' =>       'https://github.com/nf-core/{repo}/settings/branches',
  'branch_master_required_ci' =>          'https://github.com/nf-core/{repo}/settings/branches',
  'branch_master_stale_reviews' =>        'https://github.com/nf-core/{repo}/settings/branches',
  'branch_master_code_owner_reviews' =>   'https://github.com/nf-core/{repo}/settings/branches',
  'branch_master_required_num_reviews' => 'https://github.com/nf-core/{repo}/settings/branches',
  'branch_master_enforce_admins' =>       'https://github.com/nf-core/{repo}/settings/branches',
  'branch_dev_strict_updates' =>          'https://github.com/nf-core/{repo}/settings/branches',
  'branch_dev_required_ci' =>             'https://github.com/nf-core/{repo}/settings/branches',
  'branch_dev_stale_reviews' =>           'https://github.com/nf-core/{repo}/settings/branches',
  'branch_dev_code_owner_reviews' =>      'https://github.com/nf-core/{repo}/settings/branches',
  'branch_dev_required_num_reviews' =>    'https://github.com/nf-core/{repo}/settings/branches',
  'branch_dev_enforce_admins' =>          'https://github.com/nf-core/{repo}/settings/branches',
];
$base_merge_table_col_headings = [
    'Team access' => [
      'team_all',
      'team_core',
    ],
    'Branches exist' => [
      'branch_master_exists',
      'branch_dev_exists',
      'branch_template_exists',
    ],
    'Branch protection: master' => [
      'branch_master_strict_updates',
      'branch_master_required_ci',
      'branch_master_stale_reviews',
      'branch_master_code_owner_reviews',
      'branch_master_required_num_reviews',
      'branch_master_enforce_admins',
    ],
    'Branch protection: dev' => [
      'branch_dev_strict_updates',
      'branch_dev_required_ci',
      'branch_dev_stale_reviews',
      'branch_dev_code_owner_reviews',
      'branch_dev_required_num_reviews',
      'branch_dev_enforce_admins',
    ],
];


$pipeline_test_names = [
  'has_release' => 'Released',
  'release_after_tools' => 'Released after tools',
  ] + $base_test_names;
$pipeline_test_descriptions = [
  'has_release' => 'Has at least one release',
  'release_after_tools' => 'Last release is after latest tools release (so up to date with template)',
  ] + $base_test_descriptions;
$pipeline_test_descriptions['repo_url'] = "URL should be set to https://nf-co.re/[PIPELINE-NAME]";
$pipeline_test_urls = [
  'has_release' =>         'https://github.com/nf-core/{repo}/releases',
  'release_after_tools' => 'https://github.com/nf-core/{repo}/releases',
  ] + $base_test_urls;
$pipeline_merge_table_col_headings = $base_merge_table_col_headings;

$core_repo_test_names = $base_test_names;
$core_repo_test_descriptions = $base_test_descriptions;
$core_repo_test_urls = $base_test_urls;
$core_repo_merge_table_col_headings = $base_merge_table_col_headings;
$core_repo_ignore_tests = [
  'branch_dev_exists',
  'branch_template_exists',
  'branch_master_strict_updates',
  'branch_master_required_ci',
  'branch_master_stale_reviews',
  'branch_master_code_owner_reviews',
  'branch_master_required_num_reviews',
  'branch_master_enforce_admins',
  'branch_dev_strict_updates',
  'branch_dev_required_ci',
  'branch_dev_stale_reviews',
  'branch_dev_code_owner_reviews',
  'branch_dev_required_num_reviews',
  'branch_dev_enforce_admins',
];
foreach($core_repo_ignore_tests as $key){
  unset($core_repo_test_names[$key]);
  unset($core_repo_test_descriptions[$key]);
  unset($core_repo_test_urls[$key]);
}

// Get any missing data and run tests / fix problems
$updated_teams = [];
foreach($core_repos as $idx => $core_repo){
  $core_repo->test_names = $core_repo_test_names;
  $core_repo->test_descriptions = $core_repo_test_descriptions;
  $core_repo->test_urls = $core_repo_test_urls;
  $core_repo->get_data();
  if($core_repo->gh_repo->archived){
    unset($core_repos[$idx]);
    continue;
  }
  $core_repo->run_tests();
  $core_repo->fix_tests();
}
// Tools: Get release info
$core_repos['tools']->get_release_data();
$tools_last_release = $core_repos['tools']->gh_release->published_at;
foreach($pipelines as $idx => $pipeline){
  $pipeline->test_names = $pipeline_test_names;
  $pipeline->test_descriptions = $pipeline_test_descriptions;
  $pipeline->test_urls = $pipeline_test_urls;
  $pipeline->get_data();
  if($pipeline->gh_repo->archived){
    unset($pipelines[$idx]);
    continue;
  }
  $pipeline->run_tests();
  $pipeline->fix_tests();
}


foreach($updated_teams as $team => $updated){
  if($updated){
    $_GET['action'] = 'refresh';
    get_gh_team_repos($team);
    foreach($pipelines as $pipeline){
      $pipeline->test_teams();
    }
    foreach($core_repos as $core_repo){
      $core_repo->test_teams();
    }
  }
}

ksort($pipelines);
ksort($core_repos);

?>

<div class="container-fluid main-content">
  <h2>Pipelines</h2>
  <div class="table-responsive">
    <table class="table table-hover table-sm small">
      <thead>
        <tr>
          <th class="small text-nowrap">Pipeline Name</th>
          <?php
          $m_names_printed = [];
          $colspan = '';
          foreach ($pipeline_test_names as $key => $name){
            $description = $pipeline_test_descriptions[$key];
            $print = true;
            foreach($pipeline_merge_table_col_headings as $m_name => $m_keys){
              if(in_array($key, $m_keys)){
                if(!in_array($m_name, $m_names_printed)){
                  $colspan = 'colspan="'.count($m_keys).'"';
                  $description = $m_name;
                  $name = $m_name;
                  $m_names_printed[] = $m_name;
                } else {
                  $print = false;
                }
              }
            }
            if($print) echo '<th '.$colspan.' class="small text-nowrap" title="'.$description.'" data-toggle="tooltip" data-placement="top">'.$name.'</th>';
          }
          ?>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($pipelines as $pipeline){
        echo '<tr>';
          echo '<td>'.$pipeline->name.'</td>';
          foreach ($pipeline_test_names as $key => $name){
            $pipeline->print_table_cell($key);
          }
        echo '</tr>';
      }
      ?>
      </tbody>
    </table>
  </div>

  <h2>Core repos</h2>
  <div class="table-responsive">
    <table class="table table-hover table-sm small">
      <thead>
        <tr>
          <th class="small text-nowrap">Pipeline Name</th>
          <?php foreach ($core_repo_test_names as $key => $name){
            echo '<th class="small text-nowrap" title="'.$core_repo_test_descriptions[$key].'" data-toggle="tooltip" data-placement="top">'.$name.'</th>';
          } ?>
        </tr>
      </thead>
      <tbody>
      <?php
      foreach ($core_repos as $repo){
        echo '<tr>';
          echo '<td>'.$repo->name.'</td>';
          foreach ($core_repo_test_names as $key => $name){
            $repo->print_table_cell($key);
          }
        echo '</tr>';
      }
      ?>
      </tbody>
    </table>
  </div>

  <h2>Actions</h2>
  <form class="form-inline" action="" method="get">
    <select class="custom-select repos-select" name="repos">
      <optgroup label="All repositories">
        <option value="all" selected>All pipelines</option>
      </optgroup>
      <optgroup label="Pipelines">
      <?php foreach ($pipelines as $repo){
        echo '<option>'.$repo->name.'</option>';
      } ?>
      </optgroup>
      <optgroup label="Core Repos">
      <?php foreach ($core_repos as $repo){
        echo '<option>'.$repo->name.'</option>';
      } ?>
      </optgroup>
    </select>
    <button type="submit" name="action" value="refresh" class="btn btn-primary my-1 ml-2 refresh-btn">Refresh data</button>
    <button type="submit" name="action" value="fix" class="btn btn-info my-1 ml-1 fix-btn">Fix data</button>
  </form>
  <p><em class="small text-muted">Warning: page will take a minute or two to load. Even when refreshing one repo, some tests will be refreshed for all repos.</em></p>

</div>

<script type="text/javascript">
$(function(){
  // Disable the buttons to prevent button mashing
  $('.refresh-btn').click(function(){
    $(this).addClass('disabled').html('Refreshing &nbsp; <i class="fas fa-spinner fa-pulse"></i>');
  });
  $('.fix-btn').click(function(e){
    if(!confirm('This will attempt to change repository settings! Are you sure?')){
      e.preventDefault();
    } else {
      if($('.repos-select').val() == 'all'){
        if(!confirm('Seriously - ALL nf-core repost. Are you super sure?')){
          e.preventDefault();
        } else {
          $(this).addClass('disabled').html('Fixing &nbsp; <i class="fas fa-spinner fa-pulse"></i>');
        }
      }
    }
  });

  // Remove all get data from the URL
  if(window.location.href.includes('?')){
    var url_parts = window.location.href.split('?');
    window.history.replaceState({}, "nf-core", url_parts[0]);
  }
});
</script>



<?php
include('../includes/footer.php');
