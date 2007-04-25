<?php
//
// Definition of eZTSTranslator class
//
// Created on: <07-Jun-2002 12:40:42 amos>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish
// SOFTWARE RELEASE: 3.9.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2006 eZ systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/*! \file eztstranslator.php
*/

/*!
  \class eZTSTranslator eztstranslator.php
  \ingroup eZTranslation
  \brief This provides internationalization using XML (.ts) files

*/

include_once( "lib/ezi18n/classes/eztranslatorhandler.php" );
include_once( "lib/ezi18n/classes/eztextcodec.php" );
include_once( "lib/ezi18n/classes/eztranslationcache.php" );

class eZTSTranslator extends eZTranslatorHandler
{
    /*!
     Construct the translator and loads the translation file $file if it is set and exists.
    */
    function eZTSTranslator( $locale, $filename = null, $useCache = true )
    {
        $this->UseCache = $useCache;
        if ( isset( $GLOBALS['eZSiteBasics'] ) )
        {
            $siteBasics = $GLOBALS['eZSiteBasics'];
            if ( isset( $siteBasics['no-cache-adviced'] ) && $siteBasics['no-cache-adviced'] )
                $this->UseCache = false;
        }
        $this->BuildCache = false;
        $this->eZTranslatorHandler( true );

        $this->Locale = $locale;
        $this->File = $filename;
        $this->Messages = array();
        $this->CachedMessages = array();
        $this->HasRestoredCache = false;
        $this->RootCache = false;
    }

    /*!
     \static
     Initialize the ts translator and context if this is not already done.
    */
    function &initialize( $context, $locale, $filename, $useCache = true )
    {
        $tables =& $GLOBALS["eZTSTranslationTables"];
        $instance = false;
        $file = $locale . '/' . $filename;
        if ( isset( $tables[$file] ) and
             get_class( $tables[$file] ) == "eztstranslator" )
            $instance =& $tables[$file];
        if ( $instance and
             $instance->hasInitializedContext( $context ) )
            return $instance;
        eZDebug::createAccumulatorGroup( 'tstranslator', 'TS translator' );
        eZDebug::accumulatorStart( 'tstranslator_init', 'tstranslator', 'TS init' );
        if ( !$instance )
        {
            $instance = new eZTSTranslator( $locale, $filename, $useCache );
            $tables[$file] =& $instance;
            $manager =& eZTranslatorManager::instance();
            $manager->registerHandler( $instance );
        }
        $instance->load( $context );
        eZDebug::accumulatorStop( 'tstranslator_init' );
        return $instance;
    }

    /*!
     \return true if the context \a $context is already initialized.
    */
    function hasInitializedContext( $context )
    {
        return isset( $this->CachedMessages[$context] );
    }

    /*!
     Tries to load the context \a $requestedContext for the translation and returns true if was successful.
    */
    function load( $requestedContext )
    {
        return $this->loadTranslationFile( $this->Locale, $this->File, $requestedContext );
    }

    /*!
     \private
    */
    function loadTranslationFile( $locale, $filename, $requestedContext )
    {
        include_once( 'lib/ezfile/classes/ezdir.php' );

        // First try for current charset
        $charset = eZTextCodec::internalCharset();
        $tsTimeStamp = false;

        if ( !$this->RootCache )
        {
            $ini =& eZINI::instance();
            $roots = array( $ini->variable( 'RegionalSettings', 'TranslationRepository' ) );
            $extensionBase = eZExtension::baseDirectory();
            $translationExtensions = $ini->variable( 'RegionalSettings', 'TranslationExtensions' );
            foreach ( $translationExtensions as $translationExtension )
            {
                $extensionPath = $extensionBase . '/' . $translationExtension . '/translations';
                if ( file_exists( $extensionPath ) )
                {
                    $roots[] = $extensionPath;
                }
            }
            $this->RootCache = array( 'roots' => $roots );
        }
        else
        {
            $roots = $this->RootCache['roots'];
            if ( isset( $this->RootCache['timestamp'] ) )
                $tsTimeStamp = $this->RootCache['timestamp'];
        }

        // Load cached translations if possible
        if ( $this->UseCache == true )
        {
            if ( !$tsTimeStamp )
            {
                foreach ( $roots as $root )
                {
                    $path = eZDir::path( array( $root, $locale, $charset, $filename ) );
                    if ( file_exists( $path ) )
                    {
                        $timestamp = filemtime( $path );
                        if ( $timestamp > $tsTimeStamp )
                            $tsTimeStamp = $timestamp;
                    }
                    else
                    {
                        $path = eZDir::path( array( $root, $locale, $filename ) );
                        if ( file_exists( $path ) )
                        {
                            $timestamp = filemtime( $path );
                            if ( $timestamp > $tsTimeStamp )
                                $tsTimeStamp = $timestamp;
                        }
                    }
                }
                $this->RootCache['timestamp'] = $tsTimeStamp;
            }
            $key = 'cachecontexts';
            if ( $this->HasRestoredCache or
                 eZTranslationCache::canRestoreCache( $key, $tsTimeStamp ) )
            {
                eZDebug::accumulatorStart( 'tstranslator_cache_load', 'tstranslator', 'TS cache load' );
                if ( !$this->HasRestoredCache )
                {
                    if ( !eZTranslationCache::restoreCache( $key ) )
                    {
                        $this->BuildCache = true;
                    }
                    $contexts = eZTranslationCache::contextCache( $key );
                    if ( !is_array( $contexts ) )
                        $contexts = array();
                    $this->HasRestoredCache = $contexts;
                }
                else
                    $contexts = $this->HasRestoredCache;
                if ( !$this->BuildCache )
                {
                    $contextName = $requestedContext;
                    if ( !isset( $this->CachedMessages[$contextName] ) )
                    {
                        eZDebug::accumulatorStart( 'tstranslator_context_load', 'tstranslator', 'TS context load' );
                        if ( eZTranslationCache::canRestoreCache( $contextName, $tsTimeStamp ) )
                        {
                            if ( !eZTranslationCache::restoreCache( $contextName ) )
                            {
                                $this->BuildCache = true;
                            }
                            $this->CachedMessages[$contextName] =
                                 eZTranslationCache::contextCache( $contextName );

                            foreach ( $this->CachedMessages[$contextName] as $key => $msg )
                            {
                                $this->Messages[$key] = $msg;
                            }
                        }
                        eZDebug::accumulatorStop( 'tstranslator_context_load' );
                    }
                }
                eZDebugSetting::writeNotice( 'i18n-tstranslator', "Loading cached translation", "eZTSTranslator::loadTranslationFile" );
                eZDebug::accumulatorStop( 'tstranslator_cache_load' );
                if ( !$this->BuildCache )
                {
                    return true;
                }
            }
            eZDebugSetting::writeNotice( 'i18n-tstranslator',
                                         "Translation cache has expired. Will rebuild it from source.",
                                         "eZTSTranslator::loadTranslationFile" );
            $this->BuildCache = true;
        }

        $status = false;
        foreach ( $roots as $root )
        {
            $path = eZDir::path( array( $root, $locale, $charset, $filename ) );
            if ( !file_exists( $path ) )
            {
                $path = eZDir::path( array( $root, $locale, $filename ) );

                $ini =& eZINI::instance( "i18n.ini" );
                $fallbacks = $ini->variable( 'TranslationSettings', 'FallbackLanguages' );

                if ( array_key_exists( $locale,  $fallbacks ) and $fallbacks[$locale] )
                {
                    $fallbackpath = eZDir::path( array( $root, $fallbacks[$locale], $filename ) );
                    if ( !file_exists( $path ) and file_exists( $fallbackpath ) )
                        $path = $fallbackpath;
                }

                if ( !file_exists( $path ) )
                {
                    eZDebug::writeError( "Could not load translation file: $path", "eZTSTranslator::loadTranslationFile" );
                    continue;
                }
            }

            eZDebug::accumulatorStart( 'tstranslator_load', 'tstranslator', 'TS load' );
            $fd = fopen( $path, "rb" );
            $trans_xml = fread( $fd, filesize( $path ) );
            fclose( $fd );

            include_once( "lib/ezxml/classes/ezxml.php" );
            $xml = new eZXML();

            $tree =& $xml->domTree( $trans_xml, array(), true );

            if ( !$this->validateDOMTree( $tree ) )
            {
                eZDebug::writeWarning( "XML text for file $path did not validate", 'eZTSTranslator::loadTranslationFile' );
                continue;
            }
            $status = true;

            $treeRoot = $tree->get_root();
            $children = $treeRoot->children();
            foreach( $children as $child )
            {
                if ( $child->type == 1 )
                {
                    if ( $child->name() == "context" )
                    {
                        $this->handleContextNode( $child );
                    }
                    else
                        eZDebug::writeError( "Unknown element name: " . $child->name(),
                                             "eZTSTranslator::loadTranslationFile" );
                }
            }
            eZDebug::accumulatorStop( 'tstranslator_load' );
        }

        // Save translation cache
        if ( $this->UseCache == true && $this->BuildCache == true )
        {
            eZDebug::accumulatorStart( 'tstranslator_store_cache', 'tstranslator', 'TS store cache' );
            if ( eZTranslationCache::contextCache( 'cachecontexts' ) == null )
            {
                $contexts = array_keys( $this->CachedMessages );
                eZTranslationCache::setContextCache( 'cachecontexts',
                                                     $contexts );
                eZTranslationCache::storeCache( 'cachecontexts' );
                $this->HasRestoredCache = $contexts;
            }

            foreach ( $this->CachedMessages as $contextName => $context )
            {
                if ( eZTranslationCache::contextCache( $contextName ) == null )
                    eZTranslationCache::setContextCache( $contextName, $context );
                eZTranslationCache::storeCache( $contextName );
            }
            $this->BuildCache = false;
            eZDebug::accumulatorStop( 'tstranslator_store_cache' );
        }

        return $status;
    }

    /*!
     \static
     Validates the DOM tree \a $tree and returns true if it is correct.
     \warning There's no validation done yet. It checks if \a $tree is object only.
     In all other cases it returns \c true for all DOM trees.
    */
    function validateDOMTree( &$tree )
    {
        if ( !is_object( $tree ) )
            return false;

        return true;
/*        $xmlSchema = '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"
xmlns="http://www.w3.org/2001/XMLSchema/default">

<xsd:annotation>
  <xsd:documentation xml:lang="en">
   Translation message schema for ez.no.
   Copyright 2002 ez.no. All rights reserved.
  </xsd:documentation>
 </xsd:annotation>

 <xsd:element name="TS" type="TranslateRootType"/>

 <xsd:complexType name="TranslateRootType">
  <xsd:sequence>
   <xsd:element name="context" type="ContextType"/>
  </xsd:sequence>
 </xsd:complexType>

 <xsd:complexType name="ContextType">
  <xsd:sequence>
   <xsd:element name="name" type="xsd:string" />
   <xsd:element name="message" type="MessageType"/>
  </xsd:sequence>
 </xsd:complexType>

 <xsd:complexType name="MessageType">
  <xsd:sequence>
   <xsd:element name="source"      type="xsd:string"/>
   <xsd:element name="translation" type="TranslationType"/>
   <xsd:element name="comment"     type="xsd:string" minOccurs="0" maxOccurs="1"/>
  </xsd:sequence>
 </xsd:complexType>

 <xsd:simpleType name="TranslationType">
  <xsd:restriction base="xsd:string">
  </xsd:restriction>
  <xsd:attribute name="type" type="xsd:string" />
 </xsd:simpleType>

</xsd:schema>';

        include_once( "lib/ezxml/classes/ezschema.php" );

        $schema = new eZSchema( );
        $schema->setSchema( $xmlSchema );

        return $schema->validate( $tree );*/
    }

    function handleContextNode( $context )
    {
        $contextName = null;
        $messages = array();
        $context_children = $context->children();

        foreach( $context_children as $context_child )
        {
            if ( $context_child->type == 1 )
            {
                if ( $context_child->name() == "name" )
                {
                    $name_el = $context_child->children();
                    if ( count( $name_el ) > 0 )
                    {
                        $name_el = $name_el[0];
                        $contextName = $name_el->content;
                    }
                }
                break;
            }
        }
        if ( !$contextName )
        {
            eZDebug::writeError( "No context name found, skipping context",
                                 "eZTSTranslator::handleContextNode" );
            return false;
        }
        foreach( $context_children as $context_child )
        {
            if ( $context_child->type == 1 )
            {
                $childName = $context_child->name();
                if ( $childName == "message" )
                {
                    $this->handleMessageNode( $contextName, $context_child );
                }
                else if ( $childName == "name" )
                {
                    /* Skip name tags */
                }
                else
                {
                    eZDebug::writeError( "Unknown element name: $childName",
                                         "eZTSTranslator::handleContextNode" );
                }
            }
        }
        if ( $contextName === null )
        {
            eZDebug::writeError( "No context name found, skipping context",
                                 "eZTSTranslator::handleContextNode" );
            return false;
        }
        if ( !isset( $this->CachedMessages[$contextName] ) )
            $this->CachedMessages[$contextName] = array();

        return true;
    }

    function handleMessageNode( $contextName, &$message )
    {
        $source = null;
        $translation = null;
        $comment = null;
        $message_children = $message->children();
        foreach( $message_children as $message_child )
        {
            if ( $message_child->type == 1 )
            {
                if ( $message_child->name() == "source" )
                {
                    $source_el = $message_child->children();
                    $source_el = $source_el[0];
                    $source = $source_el->content;
                }
                else if ( $message_child->name() == "translation" )
                {
                    $translation_el = $message_child->children();
                    if ( count( $translation_el ) > 0 )
                    {
                        $translation_el = $translation_el[0];
                        $translation = $translation_el->content;
                    }
                }
                else if ( $message_child->name() == "comment" )
                {
                    $comment_el = $message_child->children();
                    $comment_el = $comment_el[0];
                    $comment = $comment_el->content;
                }
                else if ( $message_child->name() == "location" )
                {
                    //Handle location element. No functionality yet.
                }
                else
                    eZDebug::writeError( "Unknown element name: " . $message_child->name(),
                                         "eZTSTranslator::handleMessageNode" );
            }
        }
        if ( $source === null )
        {
            eZDebug::writeError( "No source name found, skipping message",
                                 "eZTSTranslator::handleMessageNode" );
            return false;
        }
        if ( $translation === null )
        {
//             eZDebug::writeError( "No translation, skipping message", "eZTSTranslator::messageNode" );
            return false;
        }
        /* we need to convert ourselves if we're using libxml stuff here */
        if ( get_class( $message ) == 'domelement' )
        {
            $codec =& eZTextCodec::instance( "utf8" );
            $source = $codec->convertString( $source );
            $translation = $codec->convertString( $translation );
            $comment = $codec->convertString( $comment );
        }

        $this->insert( $contextName, $source, $translation, $comment );
        return true;
    }

    /*!
     \reimp
    */
    function findKey( $key )
    {
        $msg = null;
        if ( isset( $this->Messages[$key] ) )
        {
            $msg = $this->Messages[$key];
        }
        return $msg;
    }

    /*!
     \reimp
    */
    function findMessage( $context, $source, $comment = null )
    {
        // First try with comment,
        $man = eZTranslatorManager::instance();
        $key = $man->createKey( $context, $source, $comment );

        if ( !isset( $this->Messages[$key] ) )
        {
            // then try without comment for general translation
            $key = $man->createKey( $context, $source );
        }

        return $this->findKey( $key );
    }

    /*!
     \reimp
    */
    function keyTranslate( $key )
    {
        $msg = $this->findKey( $key );
        if ( $msg !== null )
            return $msg["translation"];
        else
        {
            return null;
        }
    }

    /*!
     \reimp
    */
    function translate( $context, $source, $comment = null )
    {
        $msg = $this->findMessage( $context, $source, $comment );
        if ( $msg !== null )
        {
            return $msg["translation"];
        }

        return null;
    }

    /*!
     Inserts the \a $translation for the \a $context and \a $source as a translation message
     and returns the key for the message. If $comment is non-null it will be included in the message.

     If the translation message exists no new message is created and the existing key is returned.
    */
    function insert( $context, $source, $translation, $comment = null )
    {
//         eZDebug::writeDebug( "context=$context" );
        if ( $context == "" )
            $context = "default";
        $man =& eZTranslatorManager::instance();
        $key = $man->createKey( $context, $source, $comment );
//         if ( isset( $this->Messages[$key] ) )
//             return $key;
        $msg = $man->createMessage( $context, $source, $comment, $translation );
        $msg["key"] = $key;
        $this->Messages[$key] = $msg;
        // Set array of messages to be cached
        if ( $this->UseCache == true && $this->BuildCache == true )
        {
            if ( !isset( $this->CachedMessages[$context] ) )
                $this->CachedMessages[$context] = array();
            $this->CachedMessages[$context][$key] = $msg;
        }
        return $key;
    }

    /*!
     Removes the translation message with \a $context and \a $source.
     Returns true if the message was removed, false otherwise.

     If you have the translation key use removeKey() instead.
    */
    function remove( $context, $source, $message = null )
    {
        if ( $context == "" )
            $context = "default";
        $man =& eZTranslatorManager::instance();
        $key = $man->createKey( $context, $source, $message );
        if ( isset( $this->Messages[$key] ) )
            unset( $this->Messages[$key] );
    }

    /*!
     Removes the translation message with \a $key.
     Returns true if the message was removed, false otherwise.
    */
    function removeKey( $key )
    {
        if ( isset( $this->Messages[$key] ) )
            unset( $this->Messages[$key] );
    }

    /*!
     \static
     Fetche list of available translations, create eZTrnslator for each translations.
     \return list of eZTranslator objects representing available translations.
    */
    function translationList()
    {
        include_once( 'lib/ezutils/classes/ezini.php' );
        $ini =& eZINI::instance();

        $dir = $ini->variable( 'RegionalSettings', 'TranslationRepository' );

        $fileInfoList = array();
        $translationList = array();
        $locale = '';

        include_once( 'lib/ezfile/classes/ezfile.php' );
        $localeList = eZDir::findSubdirs( $dir );

        foreach( $localeList as $locale )
        {
            if ( $locale != 'untranslated' )
            {
                $translationFiles = eZDir::findSubitems( $dir . '/' . $locale, 'f' );

                foreach( $translationFiles as $translationFile )
                {
                    if ( eZFile::suffix( $translationFile ) == 'ts' )
                    {
                        $translationList[] = new eZTSTranslator( $locale,  $translationFile );
                    }
                }
            }
        }

        return $translationList;
    }

    /*!
     \static
    */
    function resetGlobals()
    {
        unset( $GLOBALS["eZTSTranslationTables"] );
    }

    /// \privatesection
    /// Contains the hash table with message translations
    var $Messages;
    var $File;
    var $UseCache;
    var $BuildCache;
    var $CachedMessages;
}

?>
