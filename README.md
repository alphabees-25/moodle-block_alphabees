# Alphabees Moodle Plugin

## Overview

The **Alphabees Moodle Plugin** adds the Alphabees AI tutor chat widget to Moodle as a block. It lets Moodle administrators place AI tutors in courses and pages while managing tutor configuration, knowledge sources, styling, and portal-managed placements through the Alphabees portal.

- Current stable release: **3.0.2**
- Supported Moodle versions: **4.1 LTS through 5.2**
- Portal: [portal.alphalearn.ai](https://portal.alphalearn.ai)
- Moodle Plugin Directory: [moodle.org/plugins/block_alphabees](https://moodle.org/plugins/block_alphabees)
- Setup video: [YouTube walkthrough](https://www.youtube.com/watch?v=ph7LRtlOa_8)

---

## How It Works

### 1. Create Your Alphabees Account

Register at [portal.alphalearn.ai](https://portal.alphalearn.ai). In the portal you can:

- Create AI tutors.
- Upload files and connect knowledge sources.
- Configure tutor behavior, prompts, and model settings.
- Customize the chat widget appearance.
- Manage Moodle placements from the portal, if enabled.

### 2. Install the Moodle Plugin

Install the plugin from the Moodle Plugin Directory:

[https://moodle.org/plugins/block_alphabees](https://moodle.org/plugins/block_alphabees)

You can also install the plugin manually by uploading the plugin ZIP file through Moodle's built-in plugin installer.

### 3. Connect Moodle to Alphabees

After installation:

1. Open **Site administration > Plugins > Blocks > Alphabees**.
2. Enter the API key from the Alphabees portal.
3. Save the plugin settings.
4. Click **Connect now** in the plugin settings.

The API key determines which Alphabees client/account this Moodle site connects to. If you change the API key later, save the settings again and click **Connect now** to reconnect the Moodle site with the new client.

### 4. Add or Manage AI Tutors

You can use Alphabees in two ways:

- Add the Alphabees block manually in Moodle and select the desired AI tutor in the block settings.
- Manage placements from the Alphabees portal, where supported, and let the plugin synchronize Moodle block instances.

---

## Features

- **Personalized AI tutoring**: Learners receive instant, course-aware support, explanations, practice prompts, and feedback in 80+ languages.
- **Learner memory and profiles**: Alphabees can build learning context over time to better support individual strengths, gaps, preferences, and progress.
- **Centralized tutor management**: Create, customize, and deploy AI tutors, support agents, and learning assistants from the Alphabees portal.
- **Knowledge management with sources**: Connect Moodle courses and uploaded knowledge sources so answers can reference the material they are based on.
- **Usage and cost control**: Manage AI usage with limits, quotas, and response controls across learners, courses, departments, or institutions.
- **Educator assistants**: Create AI assistants that help answer recurring academic, organizational, or support questions.
- **Course and exercise support**: Use AI to support course content creation, exercise generation, quiz ideas, summaries, and personalized learning activities.
- **Analytics and learner support**: Help educators identify learning gaps, review interactions, and provide more targeted support.
- **Moodle and mobile delivery**: Bring AI support into Moodle desktop and, where Moodle block rendering allows it, the official Moodle Mobile App.
- **Custom branding**: Configure colors, logos, tone of voice, and widget behavior to match your institution.

---

## Configuration Notes

- The API key is stored in the Moodle plugin settings.
- The API key is sent during site registration when **Connect now** is clicked.
- After registration, backend communication uses the signed Moodle site registration.
- If the API key is replaced, save the settings and click **Connect now** again.
- If Moodle still shows old widget behavior after an update, clear Moodle caches.

---

## Documentation and Help

- Moodle Plugin Directory: [block_alphabees](https://moodle.org/plugins/block_alphabees)
- Setup video: [Alphabees Moodle setup on YouTube](https://www.youtube.com/watch?v=ph7LRtlOa_8)
- Alphabees portal: [portal.alphalearn.ai](https://portal.alphalearn.ai)

---

## Privacy

This plugin does not store chat history in Moodle. It communicates with the Alphabees backend to provide the AI tutor experience and, when enabled, to synchronize Moodle data through the configured web-services integration. See `PRIVACY.md` for details.

---

## License

This Moodle plugin is licensed under the GNU General Public License v3.0. See `LICENSE` for details.

## Proprietary Components

The Alphabees chat widget is a proprietary, externally hosted JavaScript application loaded by this plugin. The widget is centrally managed and updated by Alphabees to provide a consistent AI tutor experience across supported platforms.

---

## Support

For support:

- Use the Alphabees portal.
- Report plugin issues through the Moodle Plugin Directory or GitHub.
- Contact support at [support@alphabees.de](mailto:support@alphabees.de).
