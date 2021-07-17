--
-- PostgreSQL database dump
--


SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: plpgsql; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS plpgsql WITH SCHEMA pg_catalog;


--
-- Name: EXTENSION plpgsql; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION plpgsql IS 'PL/pgSQL procedural language';


--
-- Name: notifications_component; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.notifications_component AS ENUM (
    'forum',
    'student',
    'grading',
    'team'
);


--
-- Name: check_valid_score(numeric, integer); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.check_valid_score(numeric, integer) RETURNS boolean
    LANGUAGE plpgsql
    AS $_$
declare
valid_score BOOLEAN;
BEGIN
   SELECT
   CASE WHEN gc_max_value >=0 THEN $1<=gc_max_value AND $1>=0
        ELSE $1>=gc_max_value AND $1<=0
   END INTO valid_score FROM gradeable_component AS gc WHERE gc.gc_id=$2;
   RETURN valid_score;
END;
$_$;


--
-- Name: csv_to_numeric_gradeable(text[], text, text); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.csv_to_numeric_gradeable(vcode text[], gradeable_id text, grader_id text) RETURNS boolean
    LANGUAGE plpgsql
    AS $$
  DECLARE
    -- Size of first array after splitting
    size INTEGER;
    -- Array of individual line after splitting
    line TEXT[];
    -- Variable to store each line in the array
    i TEXT;
    -- Array of gc_ids for this gradeable
    gcids INTEGER[];
    -- gradeable_data id for this gradeable for this student
    gdid INTEGER;
    -- Array counter
    j INTEGER;
    -- Is this gradeable component text?
    istext BOOLEAN[];
    --Score to be inserted
    score NUMERIC;
  BEGIN
    gcids := ARRAY(SELECT gc_id FROM gradeable_component WHERE g_id = gradeable_id);
    istext := ARRAY(SELECT gc_is_text FROM gradeable_component WHERE g_id = gradeable_id);
    -- Get the number of gradeable components for this gradeable. Will be used to test
    -- for uniform sized arrays
    size := array_length(gcids, 1);
    FOREACH i IN ARRAY vcode
      LOOP
        -- Split the current line
        line := string_to_array(i, ',');
        -- Check for uniform size
        IF array_length(line, 1) <> size + 1 THEN
          RAISE EXCEPTION 'INVALID SIZE: Arrays are jagged.';
        END IF;

        -- Remove any existing record for this student for this gradeable
        DELETE FROM gradeable_data WHERE gd_user_id = line[1] AND g_id = gradeable_id;

        INSERT INTO gradeable_data(g_id, gd_user_id, gd_overall_comment) VALUES (gradeable_id, line[1], '', 1);

        SELECT gd_id INTO gdid FROM gradeable_data WHERE g_id = gradeable_id AND gd_user_id = line[1];

        FOR j IN 1..size
        LOOP
          IF istext[j] THEN
          --COME BACK AND FIX: need to put in gcd_grade_time...double check to see that CSV upload still works for numeric/text
            INSERT INTO gradeable_component_data(gc_id, gd_id, gcd_component_comment, gcd_grader_id, gcd_graded_version, gcd_grade_time) VALUES (gcids[j], gdid, line[j+1], grader_id, NULL);
          ELSE
            score := CAST(line[j+1] AS NUMERIC);
            INSERT INTO gradeable_component_data(gc_id, gd_id, gcd_score, gcd_grader_id, gcd_graded_version, gcd_grade_time) VALUES (gcids[j], gdid, score, grader_id, NULL);
          END IF;
        END LOOP;

      END LOOP;
    RETURN TRUE ;
  END;
  $$;


--
-- Name: get_allowed_late_days(character varying, timestamp with time zone); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.get_allowed_late_days(character varying, timestamp with time zone) RETURNS integer
    LANGUAGE sql
    AS $_$
SELECT allowed_late_days FROM late_days WHERE user_id = $1 AND since_timestamp <= $2 ORDER BY since_timestamp DESC LIMIT 1;
$_$;


SET default_tablespace = '';

SET default_with_oids = false;

--
-- Name: categories_list; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.categories_list (
    category_id integer NOT NULL,
    category_desc character varying NOT NULL,
    rank integer,
    color character varying DEFAULT '#000080'::character varying NOT NULL
);


--
-- Name: categories_list_category_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.categories_list_category_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: categories_list_category_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.categories_list_category_id_seq OWNED BY public.categories_list.category_id;


--
-- Name: course_materials; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.course_materials (
    id integer NOT NULL,
    path character varying(255),
    type smallint NOT NULL,
    release_date timestamp with time zone,
    hidden_from_students boolean,
    priority double precision NOT NULL
);


--
-- Name: course_materials_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.course_materials_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: course_materials_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.course_materials_id_seq OWNED BY public.course_materials.id;


--
-- Name: course_materials_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.course_materials_sections (
    course_material_id integer NOT NULL,
    section_id character varying(255) NOT NULL
);


--
-- Name: electronic_gradeable; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.electronic_gradeable (
    g_id character varying(255) NOT NULL,
    eg_config_path character varying(1024) NOT NULL,
    eg_is_repository boolean NOT NULL,
    eg_subdirectory character varying(1024) NOT NULL,
    eg_vcs_host_type integer DEFAULT 0 NOT NULL,
    eg_team_assignment boolean NOT NULL,
    eg_max_team_size integer NOT NULL,
    eg_team_lock_date timestamp(6) with time zone NOT NULL,
    eg_use_ta_grading boolean NOT NULL,
    eg_scanned_exam boolean DEFAULT false NOT NULL,
    eg_student_view boolean NOT NULL,
    eg_student_view_after_grades boolean DEFAULT false NOT NULL,
    eg_student_submit boolean NOT NULL,
    eg_submission_open_date timestamp(6) with time zone NOT NULL,
    eg_submission_due_date timestamp(6) with time zone NOT NULL,
    eg_has_due_date boolean DEFAULT true NOT NULL,
    eg_late_days integer DEFAULT '-1'::integer NOT NULL,
    eg_allow_late_submission boolean DEFAULT true NOT NULL,
    eg_precision numeric NOT NULL,
    eg_regrade_allowed boolean DEFAULT true NOT NULL,
    eg_grade_inquiry_per_component_allowed boolean DEFAULT false NOT NULL,
    eg_grade_inquiry_due_date timestamp(6) with time zone NOT NULL,
    eg_thread_ids json DEFAULT '{}'::json NOT NULL,
    eg_has_discussion boolean DEFAULT false NOT NULL,
    eg_limited_access_blind integer DEFAULT 1,
    eg_peer_blind integer DEFAULT 3,
    eg_grade_inquiry_start_date timestamp(6) with time zone NOT NULL,
    eg_hidden_files character varying(1024),
    CONSTRAINT eg_grade_inquiry_due_date_max CHECK ((eg_grade_inquiry_due_date <= '9999-03-01 00:00:00-05'::timestamp with time zone)),
    CONSTRAINT eg_grade_inquiry_start_date_max CHECK ((eg_grade_inquiry_start_date <= '9999-03-01 00:00:00-05'::timestamp with time zone)),
    CONSTRAINT eg_regrade_allowed_true CHECK (((eg_regrade_allowed IS TRUE) OR (eg_grade_inquiry_per_component_allowed IS FALSE))),
    CONSTRAINT eg_submission_date CHECK ((eg_submission_open_date <= eg_submission_due_date)),
    CONSTRAINT eg_submission_due_date_max CHECK ((eg_submission_due_date <= '9999-03-01 00:00:00-05'::timestamp with time zone)),
    CONSTRAINT eg_team_lock_date_max CHECK ((eg_team_lock_date <= '9999-03-01 00:00:00-05'::timestamp with time zone))
);


--
-- Name: electronic_gradeable_data; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.electronic_gradeable_data (
    g_id character varying(255) NOT NULL,
    user_id character varying(255),
    team_id character varying(255),
    g_version integer NOT NULL,
    autograding_non_hidden_non_extra_credit numeric DEFAULT 0 NOT NULL,
    autograding_non_hidden_extra_credit numeric DEFAULT 0 NOT NULL,
    autograding_hidden_non_extra_credit numeric DEFAULT 0 NOT NULL,
    autograding_hidden_extra_credit numeric DEFAULT 0 NOT NULL,
    submission_time timestamp(6) with time zone NOT NULL,
    autograding_complete boolean DEFAULT false NOT NULL,
    CONSTRAINT egd_user_team_id_check CHECK (((user_id IS NOT NULL) OR (team_id IS NOT NULL)))
);


--
-- Name: electronic_gradeable_version; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.electronic_gradeable_version (
    g_id character varying(255) NOT NULL,
    user_id character varying(255),
    team_id character varying(255),
    active_version integer,
    CONSTRAINT egv_user_team_id_check CHECK (((user_id IS NOT NULL) OR (team_id IS NOT NULL)))
);


--
-- Name: forum_posts_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.forum_posts_history (
    post_id integer NOT NULL,
    edit_author character varying NOT NULL,
    content text NOT NULL,
    edit_timestamp timestamp with time zone NOT NULL
);


--
-- Name: grade_override; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grade_override (
    user_id character varying(255) NOT NULL,
    g_id character varying(255) NOT NULL,
    marks double precision NOT NULL,
    comment character varying
);


--
-- Name: gradeable; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable (
    g_id character varying(255) NOT NULL,
    g_title character varying(255) NOT NULL,
    g_instructions_url character varying NOT NULL,
    g_overall_ta_instructions character varying NOT NULL,
    g_gradeable_type integer NOT NULL,
    g_grader_assignment_method integer NOT NULL,
    g_ta_view_start_date timestamp(6) with time zone NOT NULL,
    g_grade_start_date timestamp(6) with time zone NOT NULL,
    g_grade_due_date timestamp(6) with time zone NOT NULL,
    g_grade_released_date timestamp(6) with time zone NOT NULL,
    g_min_grading_group integer NOT NULL,
    g_syllabus_bucket character varying(255) NOT NULL,
    g_allowed_minutes integer,
    g_allow_custom_marks boolean DEFAULT true NOT NULL,
    CONSTRAINT g_grade_due_date CHECK ((g_grade_due_date <= g_grade_released_date)),
    CONSTRAINT g_grade_start_date CHECK ((g_grade_start_date <= g_grade_due_date)),
    CONSTRAINT g_ta_view_start_date CHECK ((g_ta_view_start_date <= g_grade_start_date))
);


--
-- Name: gradeable_access; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_access (
    id integer NOT NULL,
    g_id character varying(255) NOT NULL,
    user_id character varying(255),
    team_id character varying(255),
    accessor_id character varying(255),
    "timestamp" timestamp with time zone NOT NULL,
    CONSTRAINT access_team_id_check CHECK (((user_id IS NOT NULL) OR (team_id IS NOT NULL)))
);


--
-- Name: gradeable_access_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.gradeable_access_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gradeable_access_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.gradeable_access_id_seq OWNED BY public.gradeable_access.id;


--
-- Name: gradeable_allowed_minutes_override; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_allowed_minutes_override (
    g_id character varying(255) NOT NULL,
    user_id character varying(255) NOT NULL,
    allowed_minutes integer NOT NULL
);


--
-- Name: gradeable_component; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_component (
    gc_id integer NOT NULL,
    g_id character varying(255) NOT NULL,
    gc_title character varying(255) NOT NULL,
    gc_ta_comment character varying NOT NULL,
    gc_student_comment character varying NOT NULL,
    gc_lower_clamp numeric NOT NULL,
    gc_default numeric NOT NULL,
    gc_max_value numeric NOT NULL,
    gc_upper_clamp numeric NOT NULL,
    gc_is_text boolean NOT NULL,
    gc_is_peer boolean NOT NULL,
    gc_order integer NOT NULL,
    gc_page integer NOT NULL,
    gc_is_itempool_linked boolean DEFAULT false NOT NULL,
    gc_itempool character varying(100) DEFAULT ''::character varying NOT NULL
);


--
-- Name: gradeable_component_data; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_component_data (
    gc_id integer NOT NULL,
    gd_id integer NOT NULL,
    gcd_score numeric NOT NULL,
    gcd_component_comment character varying NOT NULL,
    gcd_grader_id character varying(255) NOT NULL,
    gcd_graded_version integer,
    gcd_grade_time timestamp(6) with time zone NOT NULL,
    gcd_verifier_id character varying(255),
    gcd_verify_time timestamp without time zone
);


--
-- Name: gradeable_component_gc_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.gradeable_component_gc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gradeable_component_gc_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.gradeable_component_gc_id_seq OWNED BY public.gradeable_component.gc_id;


--
-- Name: gradeable_component_mark; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_component_mark (
    gcm_id integer NOT NULL,
    gc_id integer NOT NULL,
    gcm_points numeric NOT NULL,
    gcm_note character varying NOT NULL,
    gcm_publish boolean DEFAULT false,
    gcm_order integer NOT NULL
);


--
-- Name: gradeable_component_mark_data; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_component_mark_data (
    gc_id integer NOT NULL,
    gd_id integer NOT NULL,
    gcd_grader_id character varying(255) NOT NULL,
    gcm_id integer NOT NULL
);


--
-- Name: gradeable_component_mark_gcm_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.gradeable_component_mark_gcm_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gradeable_component_mark_gcm_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.gradeable_component_mark_gcm_id_seq OWNED BY public.gradeable_component_mark.gcm_id;


--
-- Name: gradeable_data; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_data (
    gd_id integer NOT NULL,
    g_id character varying(255) NOT NULL,
    gd_user_id character varying(255),
    gd_team_id character varying(255),
    gd_overall_comment character varying NOT NULL,
    gd_user_viewed_date timestamp(6) with time zone DEFAULT NULL::timestamp with time zone
);


--
-- Name: gradeable_data_gd_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.gradeable_data_gd_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gradeable_data_gd_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.gradeable_data_gd_id_seq OWNED BY public.gradeable_data.gd_id;


--
-- Name: gradeable_data_overall_comment; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_data_overall_comment (
    goc_id integer NOT NULL,
    g_id character varying(255) NOT NULL,
    goc_user_id character varying(255),
    goc_team_id character varying(255),
    goc_grader_id character varying(255) NOT NULL,
    goc_overall_comment character varying NOT NULL,
    CONSTRAINT goc_user_team_id_check CHECK (((goc_user_id IS NOT NULL) OR (goc_team_id IS NOT NULL)))
);


--
-- Name: gradeable_data_overall_comment_goc_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.gradeable_data_overall_comment_goc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: gradeable_data_overall_comment_goc_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.gradeable_data_overall_comment_goc_id_seq OWNED BY public.gradeable_data_overall_comment.goc_id;


--
-- Name: gradeable_teams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.gradeable_teams (
    team_id character varying(255) NOT NULL,
    g_id character varying(255) NOT NULL,
    anon_id character varying(255),
    registration_section character varying(255),
    rotating_section integer,
    team_name character varying(255) DEFAULT NULL::character varying
);


--
-- Name: grading_registration; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grading_registration (
    sections_registration_id character varying(255) NOT NULL,
    user_id character varying NOT NULL
);


--
-- Name: grading_rotating; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.grading_rotating (
    sections_rotating_id integer NOT NULL,
    user_id character varying NOT NULL,
    g_id character varying NOT NULL
);


--
-- Name: late_day_exceptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.late_day_exceptions (
    user_id character varying(255) NOT NULL,
    g_id character varying(255) NOT NULL,
    late_day_exceptions integer NOT NULL
);


--
-- Name: late_days; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.late_days (
    user_id character varying(255) NOT NULL,
    allowed_late_days integer NOT NULL,
    since_timestamp date NOT NULL
);


--
-- Name: migrations_course; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations_course (
    id character varying(100) NOT NULL,
    commit_time timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    status numeric(1,0) DEFAULT 0 NOT NULL
);


--
-- Name: notification_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notification_settings (
    user_id character varying NOT NULL,
    merge_threads boolean DEFAULT false NOT NULL,
    all_new_threads boolean DEFAULT false NOT NULL,
    all_new_posts boolean DEFAULT false NOT NULL,
    all_modifications_forum boolean DEFAULT false NOT NULL,
    reply_in_post_thread boolean DEFAULT false NOT NULL,
    team_invite boolean DEFAULT true NOT NULL,
    team_joined boolean DEFAULT true NOT NULL,
    team_member_submission boolean DEFAULT true NOT NULL,
    self_notification boolean DEFAULT false NOT NULL,
    merge_threads_email boolean DEFAULT false NOT NULL,
    all_new_threads_email boolean DEFAULT false NOT NULL,
    all_new_posts_email boolean DEFAULT false NOT NULL,
    all_modifications_forum_email boolean DEFAULT false NOT NULL,
    reply_in_post_thread_email boolean DEFAULT false NOT NULL,
    team_invite_email boolean DEFAULT true NOT NULL,
    team_joined_email boolean DEFAULT true NOT NULL,
    team_member_submission_email boolean DEFAULT true NOT NULL,
    self_notification_email boolean DEFAULT false NOT NULL
);


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id integer NOT NULL,
    component public.notifications_component NOT NULL,
    metadata text NOT NULL,
    content text NOT NULL,
    from_user_id character varying(255),
    to_user_id character varying(255) NOT NULL,
    created_at timestamp with time zone NOT NULL,
    seen_at timestamp with time zone
);


--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notifications_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: peer_assign; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.peer_assign (
    g_id character varying NOT NULL,
    grader_id character varying NOT NULL,
    user_id character varying NOT NULL
);


--
-- Name: peer_feedback; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.peer_feedback (
    grader_id character varying(255) NOT NULL,
    user_id character varying(255) NOT NULL,
    g_id character varying(255) NOT NULL,
    feedback character varying(255)
);


--
-- Name: poll_options; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.poll_options (
    option_id integer NOT NULL,
    order_id integer NOT NULL,
    poll_id integer,
    response text NOT NULL,
    correct boolean NOT NULL
);


--
-- Name: poll_responses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.poll_responses (
    poll_id integer NOT NULL,
    student_id text NOT NULL,
    option_id integer NOT NULL
);


--
-- Name: polls; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.polls (
    poll_id integer NOT NULL,
    name text NOT NULL,
    question text NOT NULL,
    status text NOT NULL,
    release_date date NOT NULL,
    image_path text,
    question_type character varying(35) DEFAULT 'single-response-multiple-correct'::character varying
);


--
-- Name: polls_poll_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.polls_poll_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: polls_poll_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.polls_poll_id_seq OWNED BY public.polls.poll_id;


--
-- Name: posts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.posts (
    id integer NOT NULL,
    thread_id integer NOT NULL,
    parent_id integer DEFAULT '-1'::integer,
    author_user_id character varying NOT NULL,
    content text NOT NULL,
    "timestamp" timestamp with time zone NOT NULL,
    anonymous boolean NOT NULL,
    deleted boolean DEFAULT false NOT NULL,
    endorsed_by character varying,
    type integer NOT NULL,
    has_attachment boolean NOT NULL,
    render_markdown boolean DEFAULT false NOT NULL
);


--
-- Name: posts_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.posts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: posts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.posts_id_seq OWNED BY public.posts.id;


--
-- Name: queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.queue (
    entry_id integer NOT NULL,
    current_state text NOT NULL,
    removal_type text,
    queue_code text NOT NULL,
    user_id text NOT NULL,
    name text NOT NULL,
    time_in timestamp with time zone NOT NULL,
    time_out timestamp with time zone,
    added_by text NOT NULL,
    help_started_by text,
    removed_by text,
    contact_info text,
    last_time_in_queue timestamp with time zone,
    time_help_start timestamp with time zone,
    paused boolean DEFAULT false NOT NULL,
    time_paused integer DEFAULT 0 NOT NULL,
    time_paused_start timestamp with time zone
);


--
-- Name: queue_entry_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.queue_entry_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: queue_entry_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.queue_entry_id_seq OWNED BY public.queue.entry_id;


--
-- Name: queue_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.queue_settings (
    id integer NOT NULL,
    open boolean NOT NULL,
    code text NOT NULL,
    token text NOT NULL
);


--
-- Name: queue_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.queue_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: queue_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.queue_settings_id_seq OWNED BY public.queue_settings.id;


--
-- Name: regrade_discussion; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regrade_discussion (
    id integer NOT NULL,
    regrade_id integer NOT NULL,
    "timestamp" timestamp with time zone NOT NULL,
    user_id character varying(255) NOT NULL,
    content text,
    deleted boolean DEFAULT false NOT NULL,
    gc_id integer
);


--
-- Name: regrade_discussion_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.regrade_discussion_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: regrade_discussion_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.regrade_discussion_id_seq OWNED BY public.regrade_discussion.id;


--
-- Name: regrade_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.regrade_requests (
    id integer NOT NULL,
    g_id character varying(255) NOT NULL,
    "timestamp" timestamp with time zone NOT NULL,
    user_id character varying(255),
    team_id character varying(255),
    status integer DEFAULT 0 NOT NULL,
    gc_id integer
);


--
-- Name: regrade_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.regrade_requests_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: regrade_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.regrade_requests_id_seq OWNED BY public.regrade_requests.id;


--
-- Name: sections_registration; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sections_registration (
    sections_registration_id character varying(255) NOT NULL
);


--
-- Name: sections_rotating; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sections_rotating (
    sections_rotating_id integer NOT NULL
);


--
-- Name: seeking_team; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.seeking_team (
    g_id character varying(255) NOT NULL,
    user_id character varying NOT NULL,
    message character varying
);


--
-- Name: solution_ta_notes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.solution_ta_notes (
    g_id character varying(255) NOT NULL,
    component_id integer NOT NULL,
    solution_notes text NOT NULL,
    author character varying NOT NULL,
    edited_at timestamp with time zone NOT NULL,
    itempool_item character varying(100) DEFAULT ''::character varying NOT NULL
);


--
-- Name: student_favorites; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.student_favorites (
    id integer NOT NULL,
    user_id character varying NOT NULL,
    thread_id integer
);


--
-- Name: student_favorites_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.student_favorites_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: student_favorites_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.student_favorites_id_seq OWNED BY public.student_favorites.id;


--
-- Name: teams; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.teams (
    team_id character varying(255) NOT NULL,
    user_id character varying(255) NOT NULL,
    state integer NOT NULL,
    last_viewed_time timestamp(6) with time zone DEFAULT NULL::timestamp with time zone
);


--
-- Name: thread_categories; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.thread_categories (
    thread_id integer NOT NULL,
    category_id integer NOT NULL
);


--
-- Name: threads; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.threads (
    id integer NOT NULL,
    title character varying NOT NULL,
    created_by character varying NOT NULL,
    pinned boolean DEFAULT false NOT NULL,
    deleted boolean DEFAULT false NOT NULL,
    merged_thread_id integer DEFAULT '-1'::integer,
    merged_post_id integer DEFAULT '-1'::integer,
    is_visible boolean NOT NULL,
    status integer DEFAULT 0 NOT NULL,
    lock_thread_date timestamp with time zone,
    pinned_expiration timestamp with time zone DEFAULT '1900-01-01 00:00:00-05'::timestamp with time zone NOT NULL,
    announced timestamp(6) with time zone DEFAULT NULL::timestamp with time zone,
    CONSTRAINT threads_status_check CHECK ((status = ANY (ARRAY['-1'::integer, 0, 1])))
);


--
-- Name: threads_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.threads_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: threads_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.threads_id_seq OWNED BY public.threads.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    user_id character varying NOT NULL,
    anon_id character varying,
    user_numeric_id character varying,
    user_firstname character varying NOT NULL,
    user_preferred_firstname character varying,
    user_lastname character varying NOT NULL,
    user_preferred_lastname character varying,
    user_email character varying NOT NULL,
    user_group integer NOT NULL,
    registration_section character varying(255),
    rotating_section integer,
    user_updated boolean DEFAULT false NOT NULL,
    instructor_updated boolean DEFAULT false NOT NULL,
    manual_registration boolean DEFAULT false,
    last_updated timestamp(6) with time zone,
    time_zone character varying DEFAULT 'NOT_SET/NOT_SET'::character varying NOT NULL,
    display_image_state character varying DEFAULT 'system'::character varying NOT NULL,
    registration_subsection character varying(255) DEFAULT ''::character varying NOT NULL,
    user_email_secondary character varying(255) DEFAULT ''::character varying NOT NULL,
    user_email_secondary_notify boolean DEFAULT false,
    CONSTRAINT users_user_group_check CHECK (((user_group >= 1) AND (user_group <= 4)))
);


--
-- Name: viewed_responses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.viewed_responses (
    thread_id integer NOT NULL,
    user_id character varying NOT NULL,
    "timestamp" timestamp with time zone NOT NULL
);


--
-- Name: categories_list category_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories_list ALTER COLUMN category_id SET DEFAULT nextval('public.categories_list_category_id_seq'::regclass);


--
-- Name: course_materials id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.course_materials ALTER COLUMN id SET DEFAULT nextval('public.course_materials_id_seq'::regclass);


--
-- Name: gradeable_access id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_access ALTER COLUMN id SET DEFAULT nextval('public.gradeable_access_id_seq'::regclass);


--
-- Name: gradeable_component gc_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component ALTER COLUMN gc_id SET DEFAULT nextval('public.gradeable_component_gc_id_seq'::regclass);


--
-- Name: gradeable_component_mark gcm_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_mark ALTER COLUMN gcm_id SET DEFAULT nextval('public.gradeable_component_mark_gcm_id_seq'::regclass);


--
-- Name: gradeable_data gd_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data ALTER COLUMN gd_id SET DEFAULT nextval('public.gradeable_data_gd_id_seq'::regclass);


--
-- Name: gradeable_data_overall_comment goc_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment ALTER COLUMN goc_id SET DEFAULT nextval('public.gradeable_data_overall_comment_goc_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: polls poll_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.polls ALTER COLUMN poll_id SET DEFAULT nextval('public.polls_poll_id_seq'::regclass);


--
-- Name: posts id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts ALTER COLUMN id SET DEFAULT nextval('public.posts_id_seq'::regclass);


--
-- Name: queue entry_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue ALTER COLUMN entry_id SET DEFAULT nextval('public.queue_entry_id_seq'::regclass);


--
-- Name: queue_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_settings ALTER COLUMN id SET DEFAULT nextval('public.queue_settings_id_seq'::regclass);


--
-- Name: regrade_discussion id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_discussion ALTER COLUMN id SET DEFAULT nextval('public.regrade_discussion_id_seq'::regclass);


--
-- Name: regrade_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests ALTER COLUMN id SET DEFAULT nextval('public.regrade_requests_id_seq'::regclass);


--
-- Name: student_favorites id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_favorites ALTER COLUMN id SET DEFAULT nextval('public.student_favorites_id_seq'::regclass);


--
-- Name: threads id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.threads ALTER COLUMN id SET DEFAULT nextval('public.threads_id_seq'::regclass);


--
-- Name: categories_list categories_list_pk; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories_list
    ADD CONSTRAINT categories_list_pk PRIMARY KEY (category_id);


--
-- Name: categories_list category_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.categories_list
    ADD CONSTRAINT category_unique UNIQUE (category_desc);


--
-- Name: course_materials course_materials_path_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.course_materials
    ADD CONSTRAINT course_materials_path_key UNIQUE (path);


--
-- Name: course_materials course_materials_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.course_materials
    ADD CONSTRAINT course_materials_pkey PRIMARY KEY (id);


--
-- Name: electronic_gradeable_data egd_g_user_team_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_data
    ADD CONSTRAINT egd_g_user_team_id_unique UNIQUE (g_id, user_id, team_id, g_version);


--
-- Name: electronic_gradeable_version egv_g_user_team_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_version
    ADD CONSTRAINT egv_g_user_team_id_unique UNIQUE (g_id, user_id, team_id);


--
-- Name: electronic_gradeable electronic_gradeable_g_id_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable
    ADD CONSTRAINT electronic_gradeable_g_id_pkey PRIMARY KEY (g_id);


--
-- Name: gradeable_data g_id_gd_team_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data
    ADD CONSTRAINT g_id_gd_team_id_unique UNIQUE (g_id, gd_team_id);


--
-- Name: grade_override grade_override_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grade_override
    ADD CONSTRAINT grade_override_pkey PRIMARY KEY (user_id, g_id);


--
-- Name: gradeable_access gradeable_access_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_access
    ADD CONSTRAINT gradeable_access_pkey PRIMARY KEY (id);


--
-- Name: gradeable_component_data gradeable_component_data_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_data
    ADD CONSTRAINT gradeable_component_data_pkey PRIMARY KEY (gc_id, gd_id, gcd_grader_id);


--
-- Name: gradeable_component_mark_data gradeable_component_mark_data_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_mark_data
    ADD CONSTRAINT gradeable_component_mark_data_pkey PRIMARY KEY (gcm_id, gc_id, gd_id, gcd_grader_id);


--
-- Name: gradeable_component_mark gradeable_component_mark_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_mark
    ADD CONSTRAINT gradeable_component_mark_pkey PRIMARY KEY (gcm_id);


--
-- Name: gradeable_component gradeable_component_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component
    ADD CONSTRAINT gradeable_component_pkey PRIMARY KEY (gc_id);


--
-- Name: gradeable_data_overall_comment gradeable_data_overall_comment_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment
    ADD CONSTRAINT gradeable_data_overall_comment_pkey PRIMARY KEY (goc_id);


--
-- Name: gradeable_data_overall_comment gradeable_data_overall_comment_team_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment
    ADD CONSTRAINT gradeable_data_overall_comment_team_unique UNIQUE (g_id, goc_team_id, goc_grader_id);


--
-- Name: gradeable_data_overall_comment gradeable_data_overall_comment_user_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment
    ADD CONSTRAINT gradeable_data_overall_comment_user_unique UNIQUE (g_id, goc_user_id, goc_grader_id);


--
-- Name: gradeable_data gradeable_data_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data
    ADD CONSTRAINT gradeable_data_pkey PRIMARY KEY (gd_id);


--
-- Name: gradeable gradeable_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable
    ADD CONSTRAINT gradeable_pkey PRIMARY KEY (g_id);


--
-- Name: regrade_requests gradeable_team_gc_id; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests
    ADD CONSTRAINT gradeable_team_gc_id UNIQUE (team_id, g_id, gc_id);


--
-- Name: gradeable_teams gradeable_teams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_teams
    ADD CONSTRAINT gradeable_teams_pkey PRIMARY KEY (team_id);


--
-- Name: gradeable_data gradeable_unqiue; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data
    ADD CONSTRAINT gradeable_unqiue UNIQUE (g_id, gd_user_id);


--
-- Name: regrade_requests gradeable_user_gc_id; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests
    ADD CONSTRAINT gradeable_user_gc_id UNIQUE (user_id, g_id, gc_id);


--
-- Name: grading_registration grading_registration_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_registration
    ADD CONSTRAINT grading_registration_pkey PRIMARY KEY (sections_registration_id, user_id);


--
-- Name: grading_rotating grading_rotating_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_rotating
    ADD CONSTRAINT grading_rotating_pkey PRIMARY KEY (sections_rotating_id, user_id, g_id);


--
-- Name: late_day_exceptions late_day_exceptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.late_day_exceptions
    ADD CONSTRAINT late_day_exceptions_pkey PRIMARY KEY (g_id, user_id);


--
-- Name: late_days late_days_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.late_days
    ADD CONSTRAINT late_days_pkey PRIMARY KEY (user_id, since_timestamp);


--
-- Name: migrations_course migrations_course_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations_course
    ADD CONSTRAINT migrations_course_pkey PRIMARY KEY (id);


--
-- Name: notification_settings notification_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_settings
    ADD CONSTRAINT notification_settings_pkey PRIMARY KEY (user_id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: peer_assign peer_assign_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_assign
    ADD CONSTRAINT peer_assign_pkey PRIMARY KEY (g_id, grader_id, user_id);


--
-- Name: peer_feedback peer_feedback_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_feedback
    ADD CONSTRAINT peer_feedback_pkey PRIMARY KEY (g_id, grader_id, user_id);


--
-- Name: course_materials_sections pk_course_material_section; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.course_materials_sections
    ADD CONSTRAINT pk_course_material_section PRIMARY KEY (course_material_id, section_id);


--
-- Name: polls polls_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.polls
    ADD CONSTRAINT polls_pkey PRIMARY KEY (poll_id);


--
-- Name: posts posts_pk; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_pk PRIMARY KEY (id);


--
-- Name: queue queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue
    ADD CONSTRAINT queue_pkey PRIMARY KEY (entry_id);


--
-- Name: queue_settings queue_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue_settings
    ADD CONSTRAINT queue_settings_pkey PRIMARY KEY (id);


--
-- Name: regrade_discussion regrade_discussion_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_discussion
    ADD CONSTRAINT regrade_discussion_pkey PRIMARY KEY (id);


--
-- Name: regrade_requests regrade_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests
    ADD CONSTRAINT regrade_requests_pkey PRIMARY KEY (id);


--
-- Name: sections_registration sections_registration_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sections_registration
    ADD CONSTRAINT sections_registration_pkey PRIMARY KEY (sections_registration_id);


--
-- Name: sections_rotating sections_rotating_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sections_rotating
    ADD CONSTRAINT sections_rotating_pkey PRIMARY KEY (sections_rotating_id);


--
-- Name: seeking_team seeking_team_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seeking_team
    ADD CONSTRAINT seeking_team_pkey PRIMARY KEY (g_id, user_id);


--
-- Name: student_favorites student_favorites_pk; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_favorites
    ADD CONSTRAINT student_favorites_pk PRIMARY KEY (id);


--
-- Name: teams teams_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_pkey PRIMARY KEY (team_id, user_id);


--
-- Name: thread_categories thread_and_category_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thread_categories
    ADD CONSTRAINT thread_and_category_unique UNIQUE (thread_id, category_id);


--
-- Name: threads threads_pk; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.threads
    ADD CONSTRAINT threads_pk PRIMARY KEY (id);


--
-- Name: student_favorites user_and_thread_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_favorites
    ADD CONSTRAINT user_and_thread_unique UNIQUE (user_id, thread_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: viewed_responses viewed_responses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.viewed_responses
    ADD CONSTRAINT viewed_responses_pkey PRIMARY KEY (thread_id, user_id);


--
-- Name: forum_posts_history_edit_timestamp_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX forum_posts_history_edit_timestamp_index ON public.forum_posts_history USING btree (edit_timestamp DESC);


--
-- Name: forum_posts_history_post_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX forum_posts_history_post_id_index ON public.forum_posts_history USING btree (post_id);


--
-- Name: gradeable_component_data_no_grader_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX gradeable_component_data_no_grader_index ON public.gradeable_component_data USING btree (gc_id, gd_id);


--
-- Name: gradeable_team_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX gradeable_team_unique ON public.regrade_requests USING btree (team_id, g_id) WHERE (gc_id IS NULL);


--
-- Name: gradeable_user_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX gradeable_user_unique ON public.regrade_requests USING btree (user_id, g_id) WHERE (gc_id IS NULL);


--
-- Name: users_user_numeric_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_user_numeric_id_idx ON public.users USING btree (user_numeric_id);


--
-- Name: electronic_gradeable_data electronic_gradeable_data_gid; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_data
    ADD CONSTRAINT electronic_gradeable_data_gid FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: electronic_gradeable_data electronic_gradeable_data_team; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_data
    ADD CONSTRAINT electronic_gradeable_data_team FOREIGN KEY (team_id) REFERENCES public.gradeable_teams(team_id) ON UPDATE CASCADE;


--
-- Name: electronic_gradeable_data electronic_gradeable_data_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_data
    ADD CONSTRAINT electronic_gradeable_data_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: electronic_gradeable electronic_gradeable_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable
    ADD CONSTRAINT electronic_gradeable_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: electronic_gradeable_version electronic_gradeable_version; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_version
    ADD CONSTRAINT electronic_gradeable_version FOREIGN KEY (g_id, user_id, team_id, active_version) REFERENCES public.electronic_gradeable_data(g_id, user_id, team_id, g_version) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: electronic_gradeable_version electronic_gradeable_version_g_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_version
    ADD CONSTRAINT electronic_gradeable_version_g_id FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: electronic_gradeable_version electronic_gradeable_version_team; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_version
    ADD CONSTRAINT electronic_gradeable_version_team FOREIGN KEY (team_id) REFERENCES public.gradeable_teams(team_id) ON UPDATE CASCADE;


--
-- Name: electronic_gradeable_version electronic_gradeable_version_user; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.electronic_gradeable_version
    ADD CONSTRAINT electronic_gradeable_version_user FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: course_materials_sections fk_course_material_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.course_materials_sections
    ADD CONSTRAINT fk_course_material_id FOREIGN KEY (course_material_id) REFERENCES public.course_materials(id) ON DELETE CASCADE;


--
-- Name: course_materials_sections fk_section_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.course_materials_sections
    ADD CONSTRAINT fk_section_id FOREIGN KEY (section_id) REFERENCES public.sections_registration(sections_registration_id) ON DELETE CASCADE;


--
-- Name: forum_posts_history forum_posts_history_edit_author_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forum_posts_history
    ADD CONSTRAINT forum_posts_history_edit_author_fk FOREIGN KEY (edit_author) REFERENCES public.users(user_id);


--
-- Name: forum_posts_history forum_posts_history_post_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.forum_posts_history
    ADD CONSTRAINT forum_posts_history_post_id_fk FOREIGN KEY (post_id) REFERENCES public.posts(id);


--
-- Name: grade_override grade_override_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grade_override
    ADD CONSTRAINT grade_override_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: grade_override grade_override_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grade_override
    ADD CONSTRAINT grade_override_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: gradeable_access gradeable_access_fk0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_access
    ADD CONSTRAINT gradeable_access_fk0 FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: gradeable_access gradeable_access_fk1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_access
    ADD CONSTRAINT gradeable_access_fk1 FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: gradeable_access gradeable_access_fk2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_access
    ADD CONSTRAINT gradeable_access_fk2 FOREIGN KEY (team_id) REFERENCES public.gradeable_teams(team_id);


--
-- Name: gradeable_access gradeable_access_fk3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_access
    ADD CONSTRAINT gradeable_access_fk3 FOREIGN KEY (accessor_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: gradeable_component_data gradeable_component_data_gc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_data
    ADD CONSTRAINT gradeable_component_data_gc_id_fkey FOREIGN KEY (gc_id) REFERENCES public.gradeable_component(gc_id) ON DELETE CASCADE;


--
-- Name: gradeable_component_data gradeable_component_data_gcd_grader_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_data
    ADD CONSTRAINT gradeable_component_data_gcd_grader_id_fkey FOREIGN KEY (gcd_grader_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: gradeable_component_data gradeable_component_data_gd_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_data
    ADD CONSTRAINT gradeable_component_data_gd_id_fkey FOREIGN KEY (gd_id) REFERENCES public.gradeable_data(gd_id) ON DELETE CASCADE;


--
-- Name: gradeable_component_data gradeable_component_data_verifier_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_data
    ADD CONSTRAINT gradeable_component_data_verifier_id_fkey FOREIGN KEY (gcd_verifier_id) REFERENCES public.users(user_id);


--
-- Name: gradeable_component gradeable_component_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component
    ADD CONSTRAINT gradeable_component_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: gradeable_component_mark_data gradeable_component_mark_data_gcm_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_mark_data
    ADD CONSTRAINT gradeable_component_mark_data_gcm_id_fkey FOREIGN KEY (gcm_id) REFERENCES public.gradeable_component_mark(gcm_id) ON DELETE CASCADE;


--
-- Name: gradeable_component_mark_data gradeable_component_mark_data_gd_id_and_gc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_mark_data
    ADD CONSTRAINT gradeable_component_mark_data_gd_id_and_gc_id_fkey FOREIGN KEY (gd_id, gc_id, gcd_grader_id) REFERENCES public.gradeable_component_data(gd_id, gc_id, gcd_grader_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: gradeable_component_mark gradeable_component_mark_gc_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_component_mark
    ADD CONSTRAINT gradeable_component_mark_gc_id_fkey FOREIGN KEY (gc_id) REFERENCES public.gradeable_component(gc_id) ON DELETE CASCADE;


--
-- Name: gradeable_data gradeable_data_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data
    ADD CONSTRAINT gradeable_data_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: gradeable_data gradeable_data_gd_team_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data
    ADD CONSTRAINT gradeable_data_gd_team_id_fkey FOREIGN KEY (gd_team_id) REFERENCES public.gradeable_teams(team_id) ON UPDATE CASCADE;


--
-- Name: gradeable_data gradeable_data_gd_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data
    ADD CONSTRAINT gradeable_data_gd_user_id_fkey FOREIGN KEY (gd_user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: gradeable_data_overall_comment gradeable_data_overall_comment_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment
    ADD CONSTRAINT gradeable_data_overall_comment_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: gradeable_data_overall_comment gradeable_data_overall_comment_goc_grader_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment
    ADD CONSTRAINT gradeable_data_overall_comment_goc_grader_id FOREIGN KEY (goc_grader_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: gradeable_data_overall_comment gradeable_data_overall_comment_goc_team_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment
    ADD CONSTRAINT gradeable_data_overall_comment_goc_team_id_fkey FOREIGN KEY (goc_team_id) REFERENCES public.gradeable_teams(team_id) ON DELETE CASCADE;


--
-- Name: gradeable_data_overall_comment gradeable_data_overall_comment_goc_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_data_overall_comment
    ADD CONSTRAINT gradeable_data_overall_comment_goc_user_id_fkey FOREIGN KEY (goc_user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: gradeable_teams gradeable_teams_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_teams
    ADD CONSTRAINT gradeable_teams_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: gradeable_teams gradeable_teams_registration_section_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_teams
    ADD CONSTRAINT gradeable_teams_registration_section_fkey FOREIGN KEY (registration_section) REFERENCES public.sections_registration(sections_registration_id);


--
-- Name: gradeable_teams gradeable_teams_rotating_section_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.gradeable_teams
    ADD CONSTRAINT gradeable_teams_rotating_section_fkey FOREIGN KEY (rotating_section) REFERENCES public.sections_rotating(sections_rotating_id);


--
-- Name: grading_registration grading_registration_sections_registration_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_registration
    ADD CONSTRAINT grading_registration_sections_registration_id_fkey FOREIGN KEY (sections_registration_id) REFERENCES public.sections_registration(sections_registration_id);


--
-- Name: grading_registration grading_registration_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_registration
    ADD CONSTRAINT grading_registration_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: grading_rotating grading_rotating_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_rotating
    ADD CONSTRAINT grading_rotating_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: grading_rotating grading_rotating_sections_rotating_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_rotating
    ADD CONSTRAINT grading_rotating_sections_rotating_fkey FOREIGN KEY (sections_rotating_id) REFERENCES public.sections_rotating(sections_rotating_id) ON DELETE CASCADE;


--
-- Name: grading_rotating grading_rotating_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.grading_rotating
    ADD CONSTRAINT grading_rotating_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: late_day_exceptions late_day_exceptions_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.late_day_exceptions
    ADD CONSTRAINT late_day_exceptions_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: late_day_exceptions late_day_exceptions_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.late_day_exceptions
    ADD CONSTRAINT late_day_exceptions_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: late_days late_days_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.late_days
    ADD CONSTRAINT late_days_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: notification_settings notification_settings_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notification_settings
    ADD CONSTRAINT notification_settings_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: notifications notifications_from_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_from_user_id_fkey FOREIGN KEY (from_user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: notifications notifications_to_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_to_user_id_fkey FOREIGN KEY (to_user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: peer_assign peer_assign_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_assign
    ADD CONSTRAINT peer_assign_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: peer_assign peer_assign_grader_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_assign
    ADD CONSTRAINT peer_assign_grader_id_fkey FOREIGN KEY (grader_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: peer_assign peer_assign_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_assign
    ADD CONSTRAINT peer_assign_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: peer_feedback peer_feedback_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_feedback
    ADD CONSTRAINT peer_feedback_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON DELETE CASCADE;


--
-- Name: peer_feedback peer_feedback_grader_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_feedback
    ADD CONSTRAINT peer_feedback_grader_id_fkey FOREIGN KEY (grader_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: peer_feedback peer_feedback_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.peer_feedback
    ADD CONSTRAINT peer_feedback_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON DELETE CASCADE;


--
-- Name: poll_options poll_options_poll_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.poll_options
    ADD CONSTRAINT poll_options_poll_id_fkey FOREIGN KEY (poll_id) REFERENCES public.polls(poll_id);


--
-- Name: poll_responses poll_responses_poll_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.poll_responses
    ADD CONSTRAINT poll_responses_poll_id_fkey FOREIGN KEY (poll_id) REFERENCES public.polls(poll_id);


--
-- Name: poll_responses poll_responses_student_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.poll_responses
    ADD CONSTRAINT poll_responses_student_id_fkey FOREIGN KEY (student_id) REFERENCES public.users(user_id);


--
-- Name: posts posts_fk0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_fk0 FOREIGN KEY (thread_id) REFERENCES public.threads(id);


--
-- Name: posts posts_fk1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.posts
    ADD CONSTRAINT posts_fk1 FOREIGN KEY (author_user_id) REFERENCES public.users(user_id);


--
-- Name: queue queue_added_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue
    ADD CONSTRAINT queue_added_by_fkey FOREIGN KEY (added_by) REFERENCES public.users(user_id);


--
-- Name: queue queue_help_started_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue
    ADD CONSTRAINT queue_help_started_by_fkey FOREIGN KEY (help_started_by) REFERENCES public.users(user_id);


--
-- Name: queue queue_removed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue
    ADD CONSTRAINT queue_removed_by_fkey FOREIGN KEY (removed_by) REFERENCES public.users(user_id);


--
-- Name: queue queue_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.queue
    ADD CONSTRAINT queue_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: regrade_discussion regrade_discussion_fk0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_discussion
    ADD CONSTRAINT regrade_discussion_fk0 FOREIGN KEY (regrade_id) REFERENCES public.regrade_requests(id);


--
-- Name: regrade_discussion regrade_discussion_fk1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_discussion
    ADD CONSTRAINT regrade_discussion_fk1 FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: regrade_discussion regrade_discussion_regrade_requests_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_discussion
    ADD CONSTRAINT regrade_discussion_regrade_requests_id_fk FOREIGN KEY (regrade_id) REFERENCES public.regrade_requests(id) ON UPDATE CASCADE;


--
-- Name: regrade_requests regrade_requests_fk0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests
    ADD CONSTRAINT regrade_requests_fk0 FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id);


--
-- Name: regrade_requests regrade_requests_fk1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests
    ADD CONSTRAINT regrade_requests_fk1 FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: regrade_requests regrade_requests_fk2; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests
    ADD CONSTRAINT regrade_requests_fk2 FOREIGN KEY (team_id) REFERENCES public.gradeable_teams(team_id);


--
-- Name: regrade_requests regrade_requests_fk3; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.regrade_requests
    ADD CONSTRAINT regrade_requests_fk3 FOREIGN KEY (gc_id) REFERENCES public.gradeable_component(gc_id);


--
-- Name: seeking_team seeking_team_g_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.seeking_team
    ADD CONSTRAINT seeking_team_g_id_fkey FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: solution_ta_notes solution_ta_notes_author_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.solution_ta_notes
    ADD CONSTRAINT solution_ta_notes_author_fk FOREIGN KEY (author) REFERENCES public.users(user_id);


--
-- Name: solution_ta_notes solution_ta_notes_g_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.solution_ta_notes
    ADD CONSTRAINT solution_ta_notes_g_id_fk FOREIGN KEY (g_id) REFERENCES public.gradeable(g_id);


--
-- Name: student_favorites student_favorites_fk0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_favorites
    ADD CONSTRAINT student_favorites_fk0 FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- Name: student_favorites student_favorites_fk1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.student_favorites
    ADD CONSTRAINT student_favorites_fk1 FOREIGN KEY (thread_id) REFERENCES public.threads(id);


--
-- Name: teams teams_team_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_team_id_fkey FOREIGN KEY (team_id) REFERENCES public.gradeable_teams(team_id) ON DELETE CASCADE;


--
-- Name: teams teams_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.teams
    ADD CONSTRAINT teams_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: thread_categories thread_categories_fk0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thread_categories
    ADD CONSTRAINT thread_categories_fk0 FOREIGN KEY (thread_id) REFERENCES public.threads(id);


--
-- Name: thread_categories thread_categories_fk1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.thread_categories
    ADD CONSTRAINT thread_categories_fk1 FOREIGN KEY (category_id) REFERENCES public.categories_list(category_id);


--
-- Name: users users_registration_section_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_registration_section_fkey FOREIGN KEY (registration_section) REFERENCES public.sections_registration(sections_registration_id);


--
-- Name: users users_rotating_section_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_rotating_section_fkey FOREIGN KEY (rotating_section) REFERENCES public.sections_rotating(sections_rotating_id);


--
-- Name: viewed_responses viewed_responses_fk0; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.viewed_responses
    ADD CONSTRAINT viewed_responses_fk0 FOREIGN KEY (thread_id) REFERENCES public.threads(id);


--
-- Name: viewed_responses viewed_responses_fk1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.viewed_responses
    ADD CONSTRAINT viewed_responses_fk1 FOREIGN KEY (user_id) REFERENCES public.users(user_id);


--
-- PostgreSQL database dump complete
--
