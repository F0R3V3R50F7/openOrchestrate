# openOrchestrate
Intelligent, Self-Managing, User-Friendly Llama.cpp Front-End


![Logo](/images/scrnsht1.png)

## ✨ Features

### 🧠 **Intelligent Context Management**
- **Smart pruning** - Automatically summarizes older messages when context fills
- **Separate pruning server** - Use different models for chat vs summarization
- **Token tracking** - Real-time context window monitoring

### 🎨 **Professional UI**
- **Modern glass design** - Beautiful, intuitive interface
- **File attachments** - Upload and chat about text files

## 🚀 Quick Start

### Prerequisites

 [llama.cpp server](https://github.com/ggml-org/llama.cpp) running on `localhost:8080`

### How to Run

# 1. download from releases, extract and run phpdesktop-chrome.exe

## 📋 License

### Open Source (MPL 2.0)
This software is primarily licensed under the **Mozilla Public License 2.0**.

**What this means:**
- ✅ Free to use, modify, and distribute
- ✅ Keep your modifications to this file private (if not distributed)
- ✅ Use in commercial projects
- ✅ Combine with other software (proprietary or open)

**Requirements:**
- 🔄 If you distribute modified versions of **this file**, you must make source available
- 📝 Include original copyright notices
- 📄 Disclose source code of modified files

[Read full MPL 2.0 license](LICENSE)

### Commercial Licensing
For companies needing:
- **Closed-source distribution rights**
- **White-label versions**
- **Custom features and support**
- **SaaS deployment without MPL requirements**

**Contact:** joshwheatstone@gmail.com

## 🛠️ Architecture

```
Single PHP File Architecture:
index.php
├── Backend API (PHP)
│   ├── Profile management
│   ├── Chat persistence
│   ├── Automatic Message Pruning
│   └── File handling
└── Frontend UI (HTML/CSS/JS)
    ├── Modern, Friendly UI Design
    ├── Real-time token counting
    ├── Streaming responses
    └── File attachment handling
```

## 🤝 Contributing

We welcome contributions! Please note:

1. **All contributors must sign our [CLA](.github/CLA.md)**
2. **Code is licensed under MPL 2.0**
3. **Check [Issues](https://github.com/yourusername/openOrchestrate-chat/issues) for open tasks**

## 📈 Roadmap

### v1.0 (Current)
- [x] Intelligent message archival & retreival
- [x] Full server governence with automatic context length allocation
- [x] Profile system
- [x] Search Chat History
- [x] Plugin System

## 🆘 Support

- **Issues**: [GitHub Issues](https://github.com/TechnologystLabs/openOrchestrate/Issues)
- **Email**: joshwheatstone@gmail.com

## 🙏 Acknowledgements

- **[llama.cpp](https://github.com/ggerganov/llama.cpp)** - The backbone of local LLMs
- **PHP-Desktop** - The echosystem that powers all of our software
- **All contributors** - Who help make this project better

---

**Made with ❤️ by Technologyst Labs • [Follow on (Coming Soon))](url)
```
