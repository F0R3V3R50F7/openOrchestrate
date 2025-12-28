# openOrchestrate

**Local models, treated with respect**

openOrchestrate is a complete desktop application built with phpDesktop-Chrome that provides intelligent model routing, context management, and a seamless chat experience for locally-run AI models via llama.cpp.

## ✨ Features

- **Intelligent Model Routing**: Automatically routes queries to appropriate expert models (text, code, medical, custom)
- **Velocity Index**: Archives old conversations and intelligently recalls them when relevant
- **Context Pruning**: Automatically condenses long conversations while preserving key information
- **Multi-Model Support**: Run multiple GGUF models simultaneously with intelligent GPU allocation
- **Local Privacy**: All data stays on your machine - no API calls, no cloud dependencies
- **VRAM Management**: Smart detection and allocation of available GPU memory
- **File Attachment**: Upload and process text files alongside your conversations
- **Custom Expert Slots**: Define custom expert models with specific prompts and behaviors

## 🏗️ Architecture

### Technology Stack:

- **Frontend**: HTML/CSS/JavaScript (66,063 characters of styles)
- **Backend**: PHP (40,438 characters of backend code)
- **Runtime**: phpDesktop-Chrome for simplicity
- **AI Engine**: llama.cpp for local model inference
- **Total Codebase**: ~130,000+ characters

### Core Components:

- **Pipeline Engine**: Multi-stage processing for queries
- **Llama Governor**: Intelligent model routing and management
- **Velocity Index**: Long-term memory and context management

### Supported Model Types:

- General conversation (Llama 2/3, Mistral, etc.)
- Code generation (CodeLlama, DeepSeek-Coder)
- Medical/health queries (Meditron, BioMistral)
- Custom expert slots with specific prompts
- Any GGUF format model compatible with llama.cpp
