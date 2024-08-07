# OSDI Client

This extension contain tools which can be used to keep a [CiviCRM](https://civicrm.org/) instance synced with an [Action Network](https://actionnetwork.org/about) organization.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP 8.1 or higher
* CiviCRM 5.69.5 (may work with older or newer versions, but has not been tested)
* Your own custom extension built on the architecture of this extension (see [Architecture](#architecture) below)

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl osdi-client@https://github.com/lemniscus/osdi-client/archive/main.zip
cv en osdi-client
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
git clone https://github.com/lemniscus/osdi-client.git
cv en osdi-client
```

## Usage

Currently, this extension provides a framework and a set of tools for developers, but cannot be used on its own through the Civi user interface. A skilled developer will need to write additional code which sits on top of this extension and helps meet your specific requirements. See the [Architecture](#architecture) section below for details.

In the future, it will be possible to use this extension for very simple use-cases just by doing some configuration through the user interface. However, most organizations will probably still have some unique requirements that will necessitate at least a small amount of custom code.

### Create a SyncProfile

A SyncProfile is where you configure basic information about how the software connects to Action Network and which components are enabled. You can use the API v4 Explorer to create your SyncProfile, which may look like this:

````json
[
  {
    "is_default": true,
    "label": "Action Network Test Site",
    "entry_point": "https://actionnetwork.org/api/v2/",
    "api_token": "647fde17acb96647fde1c4daad",
    "classes": {
      "LocalObject": {
        "Donation": "\\Civi\\Osdi\\LocalObject\\DonationCustomized"
      },
      "Mapper": {
        "Donation": "\\Civi\\Osdi\\ActionNetwork\\Mapper\\DonationCustomized"
      },
      "BatchSyncer": {
        "Donation": "\\Civi\\Osdi\\ActionNetwork\\BatchSyncer\\DonationLimitedToGroup",
        "Person": "\\Civi\\Osdi\\ActionNetwork\\BatchSyncer\\PersonLimitedToGroup"
      },
      "CrmEventResponder": {
        "*": null,
        "Contact": "\\Civi\\Osdi\\ActionNetwork\\CrmEventResponder\\PersonBasic"
      }
    }
  }
]
````

Make sure one and only one SyncProfile is marked as default.

The **classes** field tells the software to use certain PHP classes which may override the default behavior, or to ignore certain PHP classes which provide behavior you don't need. The default registry, which you may override via a SyncProfile,  is located within the `Civi\Osdi\Container` class.

### Configure Scheduled Jobs

The extension provides API v3 actions which can be run as Scheduled Jobs:

- **Job.osdiclientprocessqueue**
- **Job.osdiclientbatchsynccontacts**
- **Job.osdiclientbatchsyncdonations**
- **Job.osdiclientbatchsynctaggings**

A `sync_profile_id` parameter is required for all jobs. The batch sync jobs also take an `origin` parameter.

### Check for errors

Sync history and status is recorded in the following places:

- `PersonSyncState` and `DonationSyncState` database entities store the linkages between Civi records and their "twins" on Action Network.
- `OsdiFlag`: this entity is created when there has been an issue syncing a person.
- `OsdiLog` entities contain detailed debugging information about the sync processes.
- A log file with an `osdi` prefix, alongside your regular Civi error/debug log file, may be created to store further debugging information. If all goes well, you won't see errors in your main log file, but it may be worth looking there too if you're trying to track down a problem.


## How this extension is built

### Overview

Some organizations may use both CiviCRM and Action Network, and may want to copy records from one system to another, or keep the two systems in sync. This kind of integration involves two complex CRM systems, each with its own database structure, business logic, API, rules and conventions. Existing records on one side may need to be matched to their counterparts on the other side, or new counterparts may need to be created. Fields on one side have different names from fields on the other side, and there are other differences between the two systems in the way data is recorded; to move records back and forth, they must be "translated". Communication between the two systems is over a limited internet API. The OSDI Client extension includes a suite of PHP classes which represent these many moving parts.

### Same basic terms

**Local** system: the CiviCRM instance within which this extension is running.

**Remote** system: Action Network.

**Twin** objects: the corresponding local and remote representations of a given entity (e.g. a person or tag), when the systems are synced.

### What is OSDI?

[Action Network's API](https://actionnetwork.org/docs/v2/), which this extension depends upon, is based on the [Open Supporter Data Interface (OSDI)](https://opensupporter.github.io/osdi-docs/). As of this writing, Action Network is the only group actively implementing OSDI for large-scale organizing. However, if additional OSDI-implementing groups come along, this CiviCRM extension has been written in a way that should allow those new implementations to be built with less effort than creating an entirely new integration from the ground up.

## Architecture<a name="architecture"></a>

### Container <a name="container"></a>

A single `Container` instance keeps track of which classes should be used, and it acts as both a factory and service locator. If you need a Donation LocalObject, ask the container for it and it will give you the right kind. If you have created custom classes to extend/modify the software's behavior, let the container know so they can be put to use.

The Container is the link between your `SyncProfile` (which provides flexible configuration) and the rest of the application.

### RemoteObjectInterface<a name="remote-object"></a>

RemoteObject classes represent records on Action Network that have API endpoints, also known as *resources* in Action Network parlance, like [People](https://actionnetwork.org/docs/v2/people) (a.k.a. activists), [Tags](https://actionnetwork.org/docs/v2/tags) and [Petition Signatures](https://actionnetwork.org/docs/v2/signatures). These classes handle CRUD operations on Action Network records.

RemoteObjects contain collections of `Civi\Osdi\ActionNetwork\Object\Field` objects. They depend on a [`RemoteSystem`](#remote-system) object to perform operations on Action Network.

Namespace: `Civi\Osdi\ActionNetwork\Object`

### LocalObjectInterface<a name="local-object"></a>

LocalObject classes are the CiviCRM-side counterparts of RemoteObjects. A LocalObject class will often correspond directly to a Civi entity; for example, the LocalObject `Tag` class deals with the Civi `Tag` entity.

In some cases, a LocalObject class will gather together related entities; for example, an instance of the `Person` LocalObject class represents a Civi `Contact` *with* an `Email`, a `Phone` and an `Address`.

LocalObjects contain collections of `Civi\Osdi\LocalObject\Field` Objects.

Namespace: `Civi\Osdi\LocalObject`

### MapperInterface<a name="mapper"></a>

Mapper classes map ("translate") [RemoteObjects](#remote-object) into [LocalObjects](#local-object) and vice versa.

Mappers are necessary because data is almost always named and/or structured differently in Civi and Action Network. For example, when the built-in `Person` Mapper maps a `Person` LocalObject to a `Person` RemoteObject, it puts the Civi `first_name` field into the Action Network `given_name` field. A few other fields, similarly, can be copied from Civi to Action Network without any modification except the field name. But for some fields, the `Person` Mapper must transform a field's data from the Civi format to the Action Network format, as in the case of the `preferred_language` field in Civi, which uses a 5-character code, and the `languages_spoken` field in Action Network, which uses a 2-character code.

Some field mappings even require a bit of business logic: for example, the `Person` Mapper considers *both* the Civi `is_opt_out` field *and* the `do_not_email` field to determine whether an Action Network Person should be "subscribed" or "unsubscribed" to the mailing list.

Some Action Network entities don't have exact analogs in Civi, and vice versa. In these cases a Mapper class codifies how to represent an entity from one system on the other. For example, the built-in `Outreach` Mapper turns Action Network [Outreaches](https://actionnetwork.org/docs/v2/outreaches) into Civi Activities. It is also an example of a Mapper that only works in one direction.

Mappers normally **do not** persist any changes; they return changed but unsaved objects to the consumer (normally a [SingleSyncer](#single-syncer)).

Namespace: `Civi\Osdi\ActionNetwork\Mapper`

### MatcherInterface<a name="matcher"></a>

The role of Matcher classes is to take an object on one system and find its corresponding object (its "twin"), if one exists, on the other system. Matchers are relevant for entities that can be updated on the Action Network side, most notably People. They may not be needed for lightweight entities, like [Taggings](https://actionnetwork.org/docs/v2/taggings), which link a Person to a Tag and don't have much identity of their own.

Namespace: `Civi\Osdi\ActionNetwork\Matcher`

### SingleSyncerInterface<a name="single-syncer"></a>

Single-Syncer classes are responsible for syncing a single object on either system with its doppelgänger on the other system.

A Single-Syncer may use a [Matcher](#matcher) to find an existing twin, and may create a new twin if one doesn't exist. It will normally use a [Mapper](#mapper) to copy data between the twins, then ask the mapped objects to save themselves.

A Single-Syncer may keep track of twins, and may track their recent history. For example, a `Person` Single-Syncer may use `PersonSyncState` records as a way to remember what it has done previously, and use this information in the future to determine whether and in which direction to sync Action Network People with their Civi twins.

Namespace: `Civi\Osdi\ActionNetwork\SingleSyncer`

### BatchSyncerInterface<a name="batch-syncer"></a>

todo


### CrmEventResponders

As the name says, these classes are concerned with responding to events in the CRM. For example, when two contacts are merged, we respond by examining what changed and queuing sync actions if necessary.

### RemoteSystem<a name="remote-system"></a>

todo

### SyncProfile<a name="sync-profile"></a>

todo

### Message classes<a name="remote-system"></a>

Various lightweight classes serve to carry data between the more algorithm-heavy classes...

### Persistant-state classes

`PersonSyncState`, `DonationSyncState`, `OsdiDeletion` and others.

## Tests

There is a generous collection of PHPUnit tests that can (and should only) be run in a [CiviCRM Buildkit environment](https://docs.civicrm.org/dev/en/latest/tools/buildkit/) and **only against a sandbox Action Network account**. The reason for this is that some entities in Action Network are immutable after creation; i.e. they cannot be deleted or changed. Running the tests will create junk in your sandbox Action Network account.

## Creating a custom extension for your use-case

~~If your requirements are extremely simple, you can use the built-in Syncers, Mappers, Matchers, LocalObjects and RemoteObjects. You will need to create a SyncProfile that combines these classes into a working package.~~ (work in progress)

Some common things you might include in your custom extension:

- A version of the `Person` LocalObject that includes custom fields specific to your organization.
- A Mapper that works with your custom `Person` LocalObject.
- A Matcher that works with your custom `Person` LocalObject.
- A new RemoteObject, LocalObject, Matcher, Mapper and Syncer to sync an entity type that this extension doesn't yet handle; for example, Contributions/[Donations](https://actionnetwork.org/docs/v2/donations). Depending on the complexity of the entities, some of these new classes may be extended from existing base classes with very little new code.
