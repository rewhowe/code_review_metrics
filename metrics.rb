require 'json'
require 'yaml'
require 'date'

API_VERSION = '1.0'.freeze
API_PATH    = '/rest/api/'.freeze

##
# Used to calculate the previous week's Sunday (~2 weeks ago)
ONE_WEEK_DAYS = 8 # offset by 1

ONE_DAY_SECONDS = 24 * 60 * 60

class PullRequest
  attr_reader :repo
  attr_reader :id
  attr_reader :state
  attr_reader :created_at
  attr_reader :updated_at
  attr_reader :author
  attr_reader :from_ref
  attr_reader :to_ref

  attr_accessor :num_comments
  attr_accessor :num_rescopes
  attr_accessor :num_files_changed
  attr_accessor :num_files_added
  attr_accessor :num_files_modified
  attr_accessor :num_files_deleted

  def initialize(pr, repo)
    @repo = repo

    @id         = pr['id']
    @state      = pr['state']
    @created_at = DateTime.strptime(pr['createdDate'].to_s, '%Q').new_offset('+0900')
    @updated_at = DateTime.strptime(pr['updatedDate'].to_s, '%Q').new_offset('+0900')
    @author     = User.get_or_new pr['author']['user']
    @from_ref   = pr['fromRef']['displayId']
    @to_ref     = pr['toRef']['displayId']

    @num_comments = 0
    @num_rescopes = 0
    @num_files_changed = 0
    @num_files_added = 0
    @num_files_modified = 0
    @num_files_deleted = 0
  end

  ##
  # Exclude weekend days, unless the PR was created or merged on a weekend.
  def time_to_merge_s
    seconds = @updated_at.to_time.to_i - @created_at.to_time.to_i

    unless @created_at.saturday? || @created_at.sunday? || @updated_at.saturday? || @updated_at.sunday?
      (@created_at .. @updated_at).each do |day|
        seconds -= ONE_DAY_SECONDS if day.saturday? || day.sunday?
      end
    end

    seconds
  end
end

class Activity
  attr_reader :created_at
  attr_reader :user
  attr_reader :action

  def initialize(activity)
    @created_at = DateTime.strptime(activity['createdDate'].to_s, '%Q').new_offset('+0900')
    @user       = User.get_or_new activity['user']
    @action     = activity['action']
  end
end

class Change
  attr_reader :type

  def initialize(change)
    @type = change['type']
  end
end

class User
  @@users = {}

  attr_reader :name
  attr_accessor :num_prs_created
  attr_accessor :num_prs_reviewed
  attr_accessor :num_prs_approved
  attr_accessor :num_comments

  def initialize(user)
    @name = user['name']

    @num_prs_created = 0
    @num_prs_reviewed = 0
    @num_prs_approved = 0
    @num_comments = 0;
  end

  def self.get_or_new(user)
    @@users[user['name']] ||= User.new(user)
  end

  def self.all
    @@users
  end

  def inactive?
    @num_prs_created + @num_prs_reviewed + @num_prs_approved + @num_comments == 0
  end
end

class Metrics
  def initialize
    @config = begin
      config = YAML.load_file('./config.yaml') || exit('Cannot find config.yaml')
      {
        url: config['base_url'].chomp('/') + API_PATH + API_VERSION,
        project: config['project'],
        repos: config['repos'],
        headers: [
          '-H "Content-Type: application/json"',
          "-H \"Authorization: Bearer #{config['token']}\"",
        ],
      }
    end

    @activity_target_start_date = Date.today - Date.today.wday + 1    # monday
    date_arg = ARGV.find { |d| d =~ /--date=\d{4}-\d{2}-\d{2}/ }
    if date_arg
      @activity_target_start_date = Date.parse((date_arg.match(/--date=(\d{4}-\d{2}-\d{2})/)).captures.first)
    end
    @pull_request_target_start_date = @activity_target_start_date - ONE_WEEK_DAYS # last week sunday
  end

  def run
    pull_requests = fetch_pull_requests

    num_new_prs = pull_requests.count { |pr| pr.created_at >= @activity_target_start_date }
    merged_pull_requests = pull_requests.select do |pr|
      pr.updated_at >= @activity_target_start_date && pr.state == 'MERGED'
    end
    num_merged_prs = merged_pull_requests.size

    fetch_activities pull_requests
    fetch_changes merged_pull_requests

    member_info = {}
    User.all.each do |name, user|
      next if user.inactive?
      member_info[name] = {
        num_prs_created: user.num_prs_created,
        num_prs_reviewed: user.num_prs_reviewed,
        num_prs_approved: user.num_prs_approved,
        num_comments: user.num_comments,
      }
    end

    merged_pr_info = merged_pull_requests.map do |pull_request|
      {
        repo: pull_request.repo,
        id: pull_request.id,
        author: pull_request.author.name,
        time_to_merge_s: pull_request.time_to_merge_s,
        from_ref: pull_request.from_ref,
        to_ref: pull_request.to_ref,
        num_comments: pull_request.num_comments,
        num_rescopes: pull_request.num_rescopes,
        num_files_changed: pull_request.num_files_changed,
        num_files_added: pull_request.num_files_added,
        num_files_modified: pull_request.num_files_modified,
        num_files_deleted: pull_request.num_files_deleted,
      }
    end

    metrics = {
      pull_request_target_start_date: @pull_request_target_start_date.to_s,
      activity_target_start_date: @activity_target_start_date.to_s,
      recorded_at: DateTime.now.new_offset('+0900').to_s,
      num_new_prs: num_new_prs,
      num_merged_prs: num_merged_prs,
      member_info: member_info,
      merged_pr_info: merged_pr_info,
    }

    f = File.open "metrics_#{Date.today}.json", 'w'
    f.puts metrics.to_json
    f.close
  end

  def request(url, **params)
    url += '?' + params.map { |k, v| "#{k}=#{v}" }.join('&') unless params.nil?
    puts "request: #{url}" if ARGV.include? '--debug'
    curl_command = [
      'curl',
      '-X GET',
      *@config[:headers],
      "\"#{url}\"",
      '2> /dev/null',
    ]
    JSON.parse `#{curl_command.join ' '}`
  end

  def fetch_pull_requests
    pull_requests = []
    @config[:repos].each do |repo|
      url = format "#{@config[:url]}/projects/%s/repos/%s/pull-requests", @config[:project], repo

      is_last_page = false
      next_page_start = 0

      until is_last_page do
        response = request url, state: 'all', start: next_page_start
        next_page_start = response['nextPageStart']
        is_last_page = response['isLastPage'] || next_page_start.nil?

        response['values'].each do |pr|
          pull_request = PullRequest.new pr, repo

          if pull_request.updated_at < @pull_request_target_start_date
            is_last_page = true
            break
          end

          pull_requests.push pull_request

          if pull_request.created_at >= @activity_target_start_date
            pull_request.author.num_prs_created += 1
          end
        end
      end
    end
    pull_requests
  end

  def fetch_activities(pull_requests)
    pull_requests.each do |pull_request|
      url = format(
        "#{@config[:url]}/projects/%s/repos/%s/pull-requests/%d/activities",
        @config[:project],
        pull_request.repo,
        pull_request.id
      )

      is_last_page = false
      next_page_start = 0

      reviewed_users = {}
      approved_users = {}

      until is_last_page do
        response = request url, start: next_page_start
        next_page_start = response['nextPageStart']
        is_last_page = response['isLastPage'] || next_page_start.nil?

        response['values'].each do |a|
          activity = Activity.new a

          is_this_week_activity = activity.created_at >= @activity_target_start_date
          is_user_same_as_author = pull_request.author.name == activity.user.name

          case activity.action
          when 'COMMENTED'
            pull_request.num_comments += 1

            next unless is_this_week_activity # only count comments for this week
            next if is_user_same_as_author    # don't count comments on own PR

            activity.user.num_comments += 1

            unless reviewed_users.key? activity.user.name
              activity.user.num_prs_reviewed += 1
              reviewed_users[activity.user.name] = true
            end
          when 'APPROVED'
            next unless is_this_week_activity

            unless approved_users.key? activity.user.name
              activity.user.num_prs_approved += 1
              approved_users[activity.user.name] = true
            end

            unless reviewed_users.key? activity.user.name
              activity.user.num_prs_reviewed += 1
              reviewed_users[activity.user.name] = true
            end
          when 'REVIEWED' # marked as "needs work"
            next unless is_this_week_activity

            unless reviewed_users.key? activity.user.name
              activity.user.num_prs_reviewed += 1
              reviewed_users[activity.user.name] = true
            end
          when 'RESCOPED'
            pull_request.num_rescopes += 1
          end
        end
      end
    end
  end

  def fetch_changes(merged_pull_requests)
    merged_pull_requests.each do |pull_request|
      url = format(
        "#{@config[:url]}/projects/%s/repos/%s/pull-requests/%d/changes",
        @config[:project],
        pull_request.repo,
        pull_request.id
      )

      is_last_page = false
      next_page_start = 0

      until is_last_page do
        response = request url, start: next_page_start
        next_page_start = response['nextPageStart']
        is_last_page = response['isLastPage'] || next_page_start.nil?

        response['values'].each do |c|
           change = Change.new c
           pull_request.num_files_changed += 1

           case change.type
           when 'ADD'    then pull_request.num_files_added    += 1
           when 'MODIFY' then pull_request.num_files_modified +=1
           when 'DELETE' then pull_request.num_files_deleted  +=1
           end
        end
      end
    end
  end
end

Metrics.new.run
