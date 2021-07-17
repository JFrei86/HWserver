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
-- Name: dblink; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS dblink WITH SCHEMA public;


--
-- Name: EXTENSION dblink; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION dblink IS 'connect to other PostgreSQL databases from within a database';


--
-- Name: pgcrypto; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;


--
-- Name: EXTENSION pgcrypto; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON EXTENSION pgcrypto IS 'cryptographic functions';


--
-- Name: generate_api_key(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.generate_api_key() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
-- TRIGGER function to generate api_key on INSERT or UPDATE of user_password in
-- table users.
BEGIN
    NEW.api_key := encode(gen_random_bytes(16), 'hex');
    RETURN NEW;
END;
$$;


--
-- Name: sync_courses_user(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sync_courses_user() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    user_row record;
    db_conn varchar;
    query_string text;
BEGIN
    db_conn := format('dbname=submitty_%s_%s', NEW.semester, NEW.course);

    IF (TG_OP = 'INSERT') THEN
        -- FULL data sync on INSERT of a new user record.
        SELECT * INTO user_row FROM users WHERE user_id=NEW.user_id;
        query_string := 'INSERT INTO users (user_id, user_numeric_id, user_firstname, user_preferred_firstname, user_lastname, user_preferred_lastname, user_email, user_updated, instructor_updated, user_group, registration_section, manual_registration) ' ||
                        'VALUES (' || quote_literal(user_row.user_id) || ', ' || quote_nullable(user_row.user_numeric_id) || ', ' || quote_literal(user_row.user_firstname) || ', ' || quote_nullable(user_row.user_preferred_firstname) || ', ' || quote_literal(user_row.user_lastname) || ', ' ||
                        '' || quote_nullable(user_row.user_preferred_lastname) || ', ' || quote_literal(user_row.user_email) || ', ' || quote_literal(user_row.user_updated) || ', ' || quote_literal(user_row.instructor_updated) || ', ' ||
                        '' || NEW.user_group || ', ' || quote_nullable(NEW.registration_section) || ', ' || NEW.manual_registration || ')';
        IF query_string IS NULL THEN
            RAISE EXCEPTION 'query_string error in trigger function sync_courses_user() when doing INSERT';
        END IF;
        PERFORM dblink_exec(db_conn, query_string);
    ELSIF (TG_OP = 'UPDATE') THEN
        -- User update on registration_section
        -- CASE clause ensures user's rotating section is set NULL when
        -- registration is updated to NULL.  (e.g. student has dropped)
        query_string = 'UPDATE users SET user_group=' || NEW.user_group || ', registration_section=' || quote_nullable(NEW.registration_section) || ', rotating_section=' || CASE WHEN NEW.registration_section IS NULL THEN 'null' ELSE 'rotating_section' END || ', manual_registration=' || NEW.manual_registration || ' WHERE user_id=' || QUOTE_LITERAL(NEW.user_id);
        IF query_string IS NULL THEN
            RAISE EXCEPTION 'query_string error in trigger function sync_courses_user() when doing UPDATE';
        END IF;
        PERFORM dblink_exec(db_conn, query_string);
    END IF;

    -- All done.
    RETURN NULL;
END;
$$;


--
-- Name: sync_delete_registration_section(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sync_delete_registration_section() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
-- BEFORE DELETE trigger function to DELETE registration sections from course DB, as needed.
DECLARE
    registration_row RECORD;
    db_conn VARCHAR;
    query_string TEXT;
BEGIN
    db_conn := format('dbname=submitty_%s_%s', OLD.semester, OLD.course);
    query_string := 'DELETE FROM sections_registration WHERE sections_registration_id = ' || quote_literal(OLD.registration_section_id);
    -- Need to make sure that query_string was set properly as dblink_exec will happily take a null and then do nothing
    IF query_string IS NULL THEN
        RAISE EXCEPTION 'query_string error in trigger function sync_delete_registration_section()';
    END IF;
    PERFORM dblink_exec(db_conn, query_string);

    -- All done.  As this is a BEFORE DELETE trigger, RETURN OLD allows original triggering DELETE query to proceed.
    RETURN OLD;

-- Trying to delete a registration section while users are still enrolled will raise an integrity constraint violation exception.
-- We should catch this exception and stop execution with no rows processed.
-- No rows processed will indicate to the UsersController that deletion had an error and did not occur.
EXCEPTION WHEN integrity_constraint_violation THEN
    RAISE NOTICE 'Users are still enrolled in registration section ''%''', OLD.registration_section_id;
    -- Return NULL so we do not proceed with original triggering DELETE query.
    RETURN NULL;
END;
$$;


--
-- Name: sync_delete_user(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sync_delete_user() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
-- BEFORE DELETE trigger function to DELETE users from course DB.
DECLARE
    db_conn VARCHAR;
    query_string TEXT;
BEGIN
    db_conn := format('dbname=submitty_%s_%s', OLD.semester, OLD.course);
    query_string := 'DELETE FROM users WHERE user_id = ' || quote_literal(OLD.user_id);
    -- Need to make sure that query_string was set properly as dblink_exec will happily take a null and then do nothing
    IF query_string IS NULL THEN
        RAISE EXCEPTION 'query_string error in trigger function sync_delete_user()';
    END IF;
    PERFORM dblink_exec(db_conn, query_string);

    -- All done.  As this is a BEFORE DELETE trigger, RETURN OLD allows original triggering DELETE query to proceed.
    RETURN OLD;

-- Trying to delete a user with existing data (via foreign keys) will raise an integrity constraint violation exception.
-- We should catch this exception and stop execution with no rows processed.
-- No rows processed will indicate that deletion had an error and did not occur.
EXCEPTION WHEN integrity_constraint_violation THEN
    -- Show that an exception occurred, and what was the exception.
    RAISE NOTICE 'User ''%'' still has existing data in course DB ''%''', OLD.user_id, substring(db_conn FROM 8);
    RAISE NOTICE '%', SQLERRM;
    -- Return NULL so we do not proceed with original triggering DELETE query.
    RETURN NULL;
END;
$$;


--
-- Name: sync_delete_user_cleanup(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sync_delete_user_cleanup() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
-- AFTER DELETE trigger function removes user from master.users if they have no
-- existing course enrollment.  (i.e. no entries in courses_users)
DECLARE
    user_courses INTEGER;
BEGIN
    SELECT COUNT(*) INTO user_courses FROM courses_users WHERE user_id = OLD.user_id;
    IF user_courses = 0 THEN
        DELETE FROM users WHERE user_id = OLD.user_id;
    END IF;
    RETURN NULL;

-- The SELECT Count(*) / If check should prevent this exception, but this
-- exception handling is provided 'just in case' so process isn't interrupted.
EXCEPTION WHEN integrity_constraint_violation THEN
    -- Show that an exception occurred, and what was the exception.
    RAISE NOTICE 'Integrity constraint prevented user ''%'' from being deleted from master.users table.', OLD.user_id;
    RAISE NOTICE '%', SQLERRM;
    RETURN NULL;
END;
$$;


--
-- Name: sync_insert_registration_section(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sync_insert_registration_section() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
-- AFTER INSERT trigger function to INSERT registration sections to course DB, as needed.
DECLARE
    registration_row RECORD;
    db_conn VARCHAR;
    query_string TEXT;
BEGIN
    db_conn := format('dbname=submitty_%s_%s', NEW.semester, NEW.course);
    query_string := 'INSERT INTO sections_registration VALUES(' || quote_literal(NEW.registration_section_id) || ') ON CONFLICT DO NOTHING';
    -- Need to make sure that query_string was set properly as dblink_exec will happily take a null and then do nothing
    IF query_string IS NULL THEN
        RAISE EXCEPTION 'query_string error in trigger function sync_insert_registration_section()';
    END IF;
    PERFORM dblink_exec(db_conn, query_string);

    -- All done.
    RETURN NULL;
END;
$$;


--
-- Name: sync_user(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.sync_user() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
    DECLARE
        course_row RECORD;
        db_conn VARCHAR;
        query_string TEXT;
        preferred_name_change_details TEXT;
    BEGIN
        -- Check for changes in users.user_preferred_firstname and users.user_preferred_lastname.
        IF coalesce(OLD.user_preferred_firstname, '') <> coalesce(NEW.user_preferred_firstname, '') THEN
            preferred_name_change_details := format('PREFERRED_FIRSTNAME OLD: "%s" NEW: "%s" ', OLD.user_preferred_firstname, NEW.user_preferred_firstname);
        END IF;
        IF coalesce(OLD.user_preferred_lastname, '') <> coalesce(NEW.user_preferred_lastname, '') THEN
            preferred_name_change_details := format('%sPREFERRED_LASTNAME OLD: "%s" NEW: "%s"', preferred_name_change_details, OLD.user_preferred_lastname, NEW.user_preferred_lastname);
        END IF;
        -- If any preferred_name data has changed, preferred_name_change_details will not be NULL.
        IF preferred_name_change_details IS NOT NULL THEN
            preferred_name_change_details := format('USER_ID: "%s" %s', NEW.user_id, preferred_name_change_details);
            RAISE LOG USING MESSAGE = 'PREFERRED_NAME DATA UPDATE', DETAIL = preferred_name_change_details;
        END IF;
        -- Propagate UPDATE to course DBs
        FOR course_row IN SELECT semester, course FROM courses_users WHERE user_id=NEW.user_id LOOP
            RAISE NOTICE 'Semester: %, Course: %', course_row.semester, course_row.course;
            db_conn := format('dbname=submitty_%s_%s', course_row.semester, course_row.course);
            query_string := 'UPDATE users SET user_numeric_id=' || quote_nullable(NEW.user_numeric_id) || ', user_firstname=' || quote_literal(NEW.user_firstname) || ', user_preferred_firstname=' || quote_nullable(NEW.user_preferred_firstname) || ', user_lastname=' || quote_literal(NEW.user_lastname) || ', user_preferred_lastname=' || quote_nullable(NEW.user_preferred_lastname) || ', user_email=' || quote_literal(NEW.user_email) || ', user_email_secondary=' || quote_literal(NEW.user_email_secondary) || ',user_email_secondary_notify=' || quote_literal(NEW.user_email_secondary_notify) || ', time_zone=' || quote_literal(NEW.time_zone)  || ', display_image_state=' || quote_literal(NEW.display_image_state)  || ', user_updated=' || quote_literal(NEW.user_updated) || ', instructor_updated=' || quote_literal(NEW.instructor_updated) || ' WHERE user_id=' || quote_literal(NEW.user_id);
            -- Need to make sure that query_string was set properly as dblink_exec will happily take a null and then do nothing
            IF query_string IS NULL THEN
                RAISE EXCEPTION 'query_string error in trigger function sync_user()';
            END IF;
            PERFORM dblink_exec(db_conn, query_string);
        END LOOP;

        -- All done.
        RETURN NULL;
    END;
    $$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: courses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.courses (
    semester character varying(255) NOT NULL,
    course character varying(255) NOT NULL,
    status smallint DEFAULT 1 NOT NULL
);


--
-- Name: courses_registration_sections; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.courses_registration_sections (
    semester character varying(255) NOT NULL,
    course character varying(255) NOT NULL,
    registration_section_id character varying(255) NOT NULL
);


--
-- Name: courses_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.courses_users (
    semester character varying(255) NOT NULL,
    course character varying(255) NOT NULL,
    user_id character varying NOT NULL,
    user_group integer NOT NULL,
    registration_section character varying(255),
    manual_registration boolean DEFAULT false,
    CONSTRAINT users_user_group_check CHECK (((user_group >= 1) AND (user_group <= 4)))
);


--
-- Name: emails; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.emails (
    id integer NOT NULL,
    user_id character varying NOT NULL,
    subject text NOT NULL,
    body text NOT NULL,
    created timestamp without time zone NOT NULL,
    sent timestamp without time zone,
    error character varying DEFAULT ''::character varying NOT NULL,
    semester character varying,
    course character varying,
    email_address character varying(255) DEFAULT ''::character varying NOT NULL
);


--
-- Name: emails_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.emails_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: emails_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.emails_id_seq OWNED BY public.emails.id;


--
-- Name: mapped_courses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.mapped_courses (
    semester character varying(255) NOT NULL,
    course character varying(255) NOT NULL,
    registration_section character varying(255) NOT NULL,
    mapped_course character varying(255) NOT NULL,
    mapped_section character varying(255) NOT NULL
);


--
-- Name: migrations_master; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations_master (
    id character varying(100) NOT NULL,
    commit_time timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    status numeric(1,0) DEFAULT 0 NOT NULL
);


--
-- Name: migrations_system; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations_system (
    id character varying(100) NOT NULL,
    commit_time timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    status numeric(1,0) DEFAULT 0 NOT NULL
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    session_id character varying(255) NOT NULL,
    user_id character varying(255) NOT NULL,
    csrf_token character varying(255) NOT NULL,
    session_expires timestamp(6) with time zone NOT NULL
);


--
-- Name: terms; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.terms (
    term_id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    CONSTRAINT terms_check CHECK ((end_date > start_date))
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    user_id character varying NOT NULL,
    user_numeric_id character varying,
    user_password character varying,
    user_firstname character varying NOT NULL,
    user_preferred_firstname character varying,
    user_lastname character varying NOT NULL,
    user_preferred_lastname character varying,
    user_access_level integer DEFAULT 3 NOT NULL,
    user_email character varying NOT NULL,
    user_updated boolean DEFAULT false NOT NULL,
    instructor_updated boolean DEFAULT false NOT NULL,
    last_updated timestamp(6) with time zone,
    api_key character varying(255) DEFAULT encode(public.gen_random_bytes(16), 'hex'::text) NOT NULL,
    time_zone character varying DEFAULT 'NOT_SET/NOT_SET'::character varying NOT NULL,
    display_image_state character varying DEFAULT 'system'::character varying NOT NULL,
    user_email_secondary character varying(255) DEFAULT ''::character varying NOT NULL,
    user_email_secondary_notify boolean DEFAULT false,
    CONSTRAINT users_user_access_level_check CHECK (((user_access_level >= 1) AND (user_access_level <= 3)))
);


--
-- Name: emails id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emails ALTER COLUMN id SET DEFAULT nextval('public.emails_id_seq'::regclass);


--
-- Name: courses courses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courses
    ADD CONSTRAINT courses_pkey PRIMARY KEY (semester, course);


--
-- Name: courses_registration_sections courses_registration_sections_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courses_registration_sections
    ADD CONSTRAINT courses_registration_sections_pkey PRIMARY KEY (semester, course, registration_section_id);


--
-- Name: courses_users courses_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courses_users
    ADD CONSTRAINT courses_users_pkey PRIMARY KEY (semester, course, user_id);


--
-- Name: emails emails_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emails
    ADD CONSTRAINT emails_pkey PRIMARY KEY (id);


--
-- Name: mapped_courses mapped_courses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mapped_courses
    ADD CONSTRAINT mapped_courses_pkey PRIMARY KEY (semester, course, registration_section);


--
-- Name: migrations_master migrations_master_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations_master
    ADD CONSTRAINT migrations_master_pkey PRIMARY KEY (id);


--
-- Name: migrations_system migrations_system_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations_system
    ADD CONSTRAINT migrations_system_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (session_id);


--
-- Name: terms terms_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terms
    ADD CONSTRAINT terms_pkey PRIMARY KEY (term_id);


--
-- Name: users users_api_key_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_api_key_key UNIQUE (api_key);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (user_id);


--
-- Name: courses_users after_delete_sync_delete_user_cleanup; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER after_delete_sync_delete_user_cleanup AFTER DELETE ON public.courses_users FOR EACH ROW EXECUTE FUNCTION public.sync_delete_user_cleanup();


--
-- Name: courses_users before_delete_sync_delete_user; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER before_delete_sync_delete_user BEFORE DELETE ON public.courses_users FOR EACH ROW EXECUTE FUNCTION public.sync_delete_user();


--
-- Name: courses_registration_sections delete_sync_registration_id; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER delete_sync_registration_id BEFORE DELETE ON public.courses_registration_sections FOR EACH ROW EXECUTE FUNCTION public.sync_delete_registration_section();


--
-- Name: users generate_api_key; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER generate_api_key BEFORE INSERT OR UPDATE OF user_password ON public.users FOR EACH ROW EXECUTE FUNCTION public.generate_api_key();


--
-- Name: courses_registration_sections insert_sync_registration_id; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER insert_sync_registration_id AFTER INSERT OR UPDATE ON public.courses_registration_sections FOR EACH ROW EXECUTE FUNCTION public.sync_insert_registration_section();


--
-- Name: courses_users user_sync_courses_users; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER user_sync_courses_users AFTER INSERT OR UPDATE ON public.courses_users FOR EACH ROW EXECUTE FUNCTION public.sync_courses_user();


--
-- Name: users user_sync_users; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER user_sync_users AFTER UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.sync_user();


--
-- Name: courses courses_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courses
    ADD CONSTRAINT courses_fkey FOREIGN KEY (semester) REFERENCES public.terms(term_id) ON UPDATE CASCADE;


--
-- Name: courses_registration_sections courses_registration_sections_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courses_registration_sections
    ADD CONSTRAINT courses_registration_sections_fkey FOREIGN KEY (semester, course) REFERENCES public.courses(semester, course) ON UPDATE CASCADE;


--
-- Name: courses_users courses_users_course_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courses_users
    ADD CONSTRAINT courses_users_course_fkey FOREIGN KEY (semester, course) REFERENCES public.courses(semester, course) ON UPDATE CASCADE;


--
-- Name: courses_users courses_users_user_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.courses_users
    ADD CONSTRAINT courses_users_user_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE;


--
-- Name: emails emails_user_id_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.emails
    ADD CONSTRAINT emails_user_id_fk FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- Name: mapped_courses mapped_courses_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.mapped_courses
    ADD CONSTRAINT mapped_courses_fkey FOREIGN KEY (semester, mapped_course) REFERENCES public.courses(semester, course) ON UPDATE CASCADE;


--
-- Name: sessions sessions_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_fkey FOREIGN KEY (user_id) REFERENCES public.users(user_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

