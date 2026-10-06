# Mago Assistant add-on: VIES VAT check

A complete, working Mago Assistant skill in one class. It validates an EU VAT number against
[VIES](https://ec.europa.eu/taxation_customs/vies/), the European Commission's register, which needs
no API key.

## What it does

Ask the assistant, in the admin chat panel:

- *Is btw-nummer NL123456789B01 geldig?*
- *Check the VAT number on order 000000563*

It answers whether the number is registered, and — if the member state shares them — the registered
company name and address.

The tool itself only validates a number. For the second question the assistant first reads the
order with the built-in `order_manager` skill, which returns the billing VAT number as `vat_id`, and
then passes that on. So the admin needs the Sales permission for the order, and the VIES grant for
the check: each step is gated by the permission its data deserves, and the tool never touches the
shop's data itself.

## Install

```bash
composer require mago-assistant/magento2-vies
bin/magento module:enable MagoAssistant_Vies
bin/magento setup:upgrade
bin/magento cache:flush
```

Then grant the tool. It reads nothing from Magento, so it declares no Magento resource: it is
**refused for every admin until it is allowed per user** under Stores › Admin Assistant › Skills &
Permissions.

## The whole skill is two files

### `etc/di.xml` — the registration

```xml
<type name="MagoAssistant\Mago\Service\Tool\ToolRegistry">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="vies_vat_check" xsi:type="object">MagoAssistant\Vies\Service\Tool\VatCheck</item>
        </argument>
    </arguments>
</type>
```

That is all of it. Nothing inside `MagoAssistant_Mago` is edited, and the item name is the name the
model sees and calls.

### `Service/Tool/VatCheck.php` — the tool

One class implementing `MagoAssistant\Mago\Api\Tool\ToolInterface`. Eight methods:

| Method | What it is for |
| --- | --- |
| `getName()` | The name the model calls. Matches the `di.xml` item name. |
| `getDescription()` | How the model decides to pick this tool over another one. |
| `getParameterSchema()` | JSON Schema for the arguments. |
| `getMagentoAcl()` | The admin resource the caller must hold, or `Acl::MAGO_PER_USER` for a tool that touches no Magento data. |
| `isReadOnly()` / `isReadOnlyAction()` | Whether a call can change anything. `false` means the admin is asked to confirm first. |
| `getFieldClassification()` | How each returned field may cross to the model. |
| `getInstructions()` | Presentation guidance, delivered *after* the first call. |
| `execute()` | The work. |

## The seven things that are easy to get wrong

**1. Undeclared fields are dropped, silently.** `getFieldClassification()` is a whitelist, not a
hint. A field `execute()` returns that is not listed here never reaches the model, and you will see
the assistant answer as if the data were not there. When a skill "loses" data, check this list
first.

**2. Personal data is masked, not removed.** `name`, `address` and `vat_number` are declared
`TOKENISE`, so the model receives `mago://name_1` and the administrator sees the real value in the
panel. For a registered company that name is public record, but for a sole trader it is a person's
name, home address and personal tax number, so they are masked either way. Use `PiiClass::PUBLIC`
only for data that is genuinely not personal.

**3. The model fills in every parameter you offer.** If you put a parameter in the schema, expect it
to arrive whether or not the question called for one. Removing a parameter is a far stronger lever
than writing a description asking the model not to use it.

**4. `getInstructions()` arrives after the first call, not before.** So it is the place to say how to
present an answer, never which arguments to pass. Anything about calling belongs in
`getDescription()` and the parameter schema, which the model reads first.

**5. Never echo an argument back as `PUBLIC`.** A read-only tool receives its arguments with masked
values already filled in: if the model passes `mago://name_1`, `execute()` gets the real name. A
field that repeats the argument, even reformatted, then hands the model the value behind the token.
Validate the shape of every argument before using it, leave the input out of error messages, and
classify any echoed field as `TOKENISE`.

**6. A failed call is not a "no".** VIES answers most failures with HTTP 200 (an
`actionSucceed: false` envelope, or a `userError` such as `MS_UNAVAILABLE`). Read naively that is
`"valid": false`, and the assistant tells the administrator a number is not registered when the
register never answered. Return `['error' => ...]` instead: the `error` key always reaches the model,
whatever the classification says.

**7. A lookup does not read the shop.** The first version read the order itself to find the VAT
number, and so had to carry `Magento_Sales::actions_view` even for a number the admin typed in.
Let the core skill that owns the data read it (here `order_manager`, which returns `vat_id`), tell
the model so in `getDescription()`, and declare `Acl::MAGO_PER_USER`. The model chains the two
calls, and each one is checked on its own permission.
